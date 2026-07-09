<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillOrdersCommand extends Command
{
    protected $signature = 'acc:sync:backfill-orders
        {--dry-run : Only report how many orders are missing, do not import}
        {--limit= : Import at most this many orders this run}
        {--json : Machine-readable output}';

    protected $description = 'One-off/repair: import every hub order missing locally (the changed-feed poll only covers deltas since a cursor)';

    public function handle(HubClient $hub, OrderIngestPipeline $pipeline): int
    {
        $this->info('Fetching the full order list from the hub…');
        $hubOrders = $hub->allOrders();
        $hubIds = collect($hubOrders)->pluck('id')->map(fn ($id) => (int) $id)->unique();

        $localIds = Order::pluck('hub_order_id')->map(fn ($id) => (int) $id)->flip();
        $allMissingIds = $hubIds->reject(fn ($id) => $localIds->has($id))->values();
        $missingIds = ($limit = $this->option('limit')) ? $allMissingIds->take((int) $limit) : $allMissingIds;

        $stats = [
            'hub_total' => $hubIds->count(),
            'already_synced' => $hubIds->count() - $allMissingIds->count(),
            'missing' => $allMissingIds->count(),
            'imported' => 0,
            'failed' => 0,
            'failed_ids' => [],
        ];

        if ($this->option('dry-run')) {
            $this->info("Hub has {$stats['hub_total']} orders; {$stats['already_synced']} already synced; {$stats['missing']} missing.");

            return self::SUCCESS;
        }

        if ($missingIds->isEmpty()) {
            $this->info('Nothing to backfill — local orders already match the hub.');

            return self::SUCCESS;
        }

        $run = SyncRun::create(['type' => 'backfill_orders', 'status' => 'running', 'started_at' => now()]);
        $bar = $this->output->createProgressBar($missingIds->count());
        $bar->start();

        foreach ($missingIds as $id) {
            try {
                $pipeline->ingest($id, $hub->order($id), 'backfill');
                $stats['imported']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failed_ids'][] = $id;
                Log::error('Order backfill failed', ['hub_order_id' => $id, 'error' => $e->getMessage()]);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $run->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Backfilled {$stats['imported']} orders ({$stats['failed']} failed) out of {$stats['missing']} that were missing.");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
