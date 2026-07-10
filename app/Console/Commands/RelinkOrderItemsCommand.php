<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\OrderItem;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\SyncRun;
use Illuminate\Console\Command;

class RelinkOrderItemsCommand extends Command
{
    protected $signature = 'acc:sync:relink-order-items
        {--dry-run : Only report how many order items can now be linked, do not update}
        {--json : Machine-readable output}';

    protected $description = 'One-off/repair: link order items to product_mirror rows that did not exist yet at order-normalization time (does not touch posted profit — run acc:orders:recalc separately if needed)';

    public function handle(): int
    {
        // Same key preference as OrderNormalizer::syncItems: variation_id wins over product_id.
        $candidates = OrderItem::whereNull('product_mirror_id')
            ->where(function ($q) {
                $q->whereNotNull('hub_variation_id')->orWhereNotNull('hub_product_id');
            })
            ->get(['id', 'hub_product_id', 'hub_variation_id']);

        $mirrorIdsByHubId = ProductMirror::pluck('id', 'hub_product_id');

        $resolved = $candidates->mapWithKeys(function ($item) use ($mirrorIdsByHubId) {
            $hubId = $item->hub_variation_id ?: $item->hub_product_id;

            return [$item->id => $mirrorIdsByHubId->get($hubId)];
        })->filter();

        $stats = [
            'unlinked_total' => $candidates->count(),
            'now_linkable' => $resolved->count(),
        ];

        if ($this->option('dry-run')) {
            $this->info("{$stats['unlinked_total']} order items are unlinked; {$stats['now_linkable']} can now be matched to a synced product.");
            $this->option('json') && $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($resolved->isEmpty()) {
            $this->info('Nothing to relink — no newly-matchable order items.');

            return self::SUCCESS;
        }

        $run = SyncRun::create(['type' => 'relink_order_items', 'status' => 'running', 'started_at' => now()]);

        foreach ($resolved as $itemId => $mirrorId) {
            OrderItem::whereKey($itemId)->update(['product_mirror_id' => $mirrorId]);
        }

        $run->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Relinked {$stats['now_linkable']} order items to their now-synced products.");

        return self::SUCCESS;
    }
}
