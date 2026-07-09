<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class PollOrdersCommand extends Command
{
    protected $signature = 'acc:sync:poll-orders {--json : Machine-readable output}';

    protected $description = 'Reconciliation fallback: poll the hub for changed orders since the last cursor';

    public function handle(HubClient $hub, OrderIngestPipeline $pipeline): int
    {
        // The hub requires `since` (plain ISO, no timezone suffix); with no
        // prior cursor, cover the last day rather than failing the first run.
        $since = SyncRun::where('type', 'poll_orders')->where('status', 'done')
            ->latest('finished_at')->value('since_cursor')
            ?? Carbon::now('UTC')->subDay()->format('Y-m-d\TH:i:s');

        $run = SyncRun::create(['type' => 'poll_orders', 'status' => 'running', 'started_at' => now()]);

        // Overlap window guards against rows missed during the hub's nightly mirror swap.
        $cursor = Carbon::now('UTC')->subMinutes(config('hub.poll_overlap_minutes'))->format('Y-m-d\TH:i:s');

        try {
            $response = $hub->changedOrders($since);
            $changed = $response['orders'] ?? $response['data'] ?? (array_is_list($response) ? $response : []);

            $stats = ['seen' => count($changed), 'upserted' => 0];

            foreach ($changed as $row) {
                $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row);
                if ($id === 0) {
                    continue;
                }

                $payload = (is_array($row) && isset($row['status'])) ? $row : $hub->order($id);
                $pipeline->ingest($id, $payload, 'poll');
                $stats['upserted']++;
            }

            $run->update([
                'status' => 'done',
                'since_cursor' => $cursor,
                'stats' => $stats,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            $this->error("Poll failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Polled {$stats['seen']} changed orders, upserted {$stats['upserted']}.");

        return self::SUCCESS;
    }
}
