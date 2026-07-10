<?php

namespace App\Console\Commands;

use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\ProductSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ResyncProductWeightsCommand extends Command
{
    protected $signature = 'acc:sync:resync-product-weights
        {--limit= : Resync at most this many parent products this run}
        {--json : Machine-readable output}';

    protected $description = 'One-off: re-fetch every already-synced parent product so weight_grams (a field newly added to the hub) gets backfilled';

    public function handle(ProductSyncer $syncer): int
    {
        $ids = ProductMirror::whereNull('parent_hub_id')->pluck('hub_product_id');
        $ids = ($limit = $this->option('limit')) ? $ids->take((int) $limit) : $ids;

        $stats = ['total' => $ids->count(), 'updated' => 0, 'failed' => 0, 'failed_ids' => []];
        $correlation = (string) Str::uuid();
        $bar = $this->output->createProgressBar($stats['total']);
        $bar->start();

        foreach ($ids as $id) {
            try {
                $syncer->sync((int) $id, 'backfill', $correlation);
                $stats['updated']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failed_ids'][] = $id;
                Log::error('Product weight resync failed', ['hub_product_id' => $id, 'error' => $e->getMessage()]);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Resynced {$stats['updated']} products ({$stats['failed']} failed) out of {$stats['total']}.");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
