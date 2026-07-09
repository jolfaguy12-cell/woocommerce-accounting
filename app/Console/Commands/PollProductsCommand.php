<?php

namespace App\Console\Commands;

use App\Domain\Products\Services\ProductSyncer;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class PollProductsCommand extends Command
{
    protected $signature = 'acc:sync:poll-products {--json : Machine-readable output}';

    protected $description = 'Reconciliation fallback: poll the hub for changed products since the last cursor';

    public function handle(HubClient $hub, ProductSyncer $syncer): int
    {
        // Hub compares products against local-time post_modified, so a UTC
        // cursor only over-fetches (safe: sync is idempotent). `since` is
        // required — default the first run to the last day.
        $since = SyncRun::where('type', 'poll_products')->where('status', 'done')
            ->latest('finished_at')->value('since_cursor')
            ?? Carbon::now('UTC')->subDay()->format('Y-m-d\TH:i:s');

        $run = SyncRun::create(['type' => 'poll_products', 'status' => 'running', 'started_at' => now()]);
        $cursor = Carbon::now('UTC')->subMinutes(config('hub.poll_overlap_minutes'))->format('Y-m-d\TH:i:s');
        $correlation = (string) Str::uuid();

        try {
            $response = $hub->changedProducts($since);
            $changed = $response['products'] ?? $response['data'] ?? (array_is_list($response) ? $response : []);

            $stats = ['seen' => count($changed), 'synced' => 0];

            foreach ($changed as $row) {
                $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row);
                if ($id === 0) {
                    continue;
                }
                $syncer->sync($id, 'poll', $correlation);
                $stats['synced']++;
            }

            $run->update(['status' => 'done', 'since_cursor' => $cursor, 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'stats' => ['error' => $e->getMessage()], 'finished_at' => now()]);
            $this->error("Poll failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Polled {$stats['seen']} changed products, synced {$stats['synced']}.");

        return self::SUCCESS;
    }
}
