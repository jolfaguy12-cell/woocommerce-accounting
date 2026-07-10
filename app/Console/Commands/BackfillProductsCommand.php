<?php

namespace App\Console\Commands;

use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BackfillProductsCommand extends Command
{
    protected $signature = 'acc:sync:backfill-products
        {--dry-run : Only report how many products are missing, do not import}
        {--limit= : Import at most this many products this run}
        {--json : Machine-readable output}';

    protected $description = 'One-off/repair: mirror every hub product missing locally (the changed-feed poll only covers deltas since a cursor)';

    public function handle(HubClient $hub, ProductSyncer $syncer): int
    {
        $this->info('Fetching the full product list from the hub…');
        // The hub's /products listing only returns parent posts (simple/variable),
        // not variations — variations are fetched per-parent by ProductSyncer::sync().
        $hubProducts = $hub->allProducts();
        $hubIds = collect($hubProducts)->pluck('id')->map(fn ($id) => (int) $id)->unique();

        $localIds = ProductMirror::whereNull('parent_hub_id')->pluck('hub_product_id')->map(fn ($id) => (int) $id)->flip();
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
            $this->info("Hub has {$stats['hub_total']} products; {$stats['already_synced']} already synced; {$stats['missing']} missing.");

            return self::SUCCESS;
        }

        if ($missingIds->isEmpty()) {
            $this->info('Nothing to backfill — local products already match the hub.');

            return self::SUCCESS;
        }

        $run = SyncRun::create(['type' => 'backfill_products', 'status' => 'running', 'started_at' => now()]);
        $correlation = (string) Str::uuid();
        $bar = $this->output->createProgressBar($missingIds->count());
        $bar->start();

        foreach ($missingIds as $id) {
            try {
                $syncer->sync($id, 'backfill', $correlation);
                $stats['imported']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failed_ids'][] = $id;
                Log::error('Product backfill failed', ['hub_product_id' => $id, 'error' => $e->getMessage()]);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $run->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Backfilled {$stats['imported']} products ({$stats['failed']} failed) out of {$stats['missing']} that were missing.");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
