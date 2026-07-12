<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

            $stats = ['seen' => count($changed), 'upserted' => 0, 'failed' => 0, 'failed_ids' => []];

            foreach ($changed as $row) {
                $id = (int) (is_array($row) ? ($row['id'] ?? 0) : $row);
                if ($id === 0) {
                    continue;
                }

                try {
                    // Feed rows are stubs (no items/meta); always pull the full order.
                    $pipeline->ingest($id, $hub->order($id), 'poll');
                    $stats['upserted']++;
                } catch (Throwable $e) {
                    // One malformed order (e.g. a hub-side bad date) must never sink
                    // the whole poll — it used to: the loop died on the first bad
                    // row, nothing behind it in the batch got ingested, and since
                    // the cursor only ever advances on a clean run, the SAME
                    // poisoned row was re-fetched and re-thrown every 15 minutes
                    // indefinitely (this is exactly what production was doing).
                    // Same per-item try/catch as acc:sync:backfill-orders; quarantine
                    // to the review queue too (the scheduled poll has no operator
                    // watching stdout the way a manual backfill run does).
                    $stats['failed']++;
                    $stats['failed_ids'][] = $id;
                    Log::error('Order poll failed', ['hub_order_id' => $id, 'error' => $e->getMessage()]);
                    $this->quarantine($id, $e);
                }
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
            : $this->info("Polled {$stats['seen']} changed orders, upserted {$stats['upserted']}"
                .($stats['failed'] ? ", {$stats['failed']} sent to review" : '').'.');

        // Unlike the manually-invoked backfill command, this runs unattended every
        // 15 minutes: a per-item quarantine is the expected, handled outcome, not
        // a scheduler-alarm-worthy failure. Only a systemic problem (hub
        // unreachable, etc. — caught above) should return FAILURE here.
        return self::SUCCESS;
    }

    private function quarantine(int $hubOrderId, Throwable $e): void
    {
        $alreadyOpen = ReviewItem::where('type', 'sync_error')
            ->where('status', 'open')
            ->whereJsonContains('payload->hub_order_id', $hubOrderId)
            ->exists();

        if (! $alreadyOpen) {
            ReviewItem::open('sync_error', null, [
                'hub_order_id' => $hubOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
