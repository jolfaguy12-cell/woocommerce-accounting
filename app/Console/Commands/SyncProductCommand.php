<?php

namespace App\Console\Commands;

use App\Domain\Products\Services\ProductSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SyncProductCommand extends Command
{
    protected $signature = 'acc:sync:product {hub_product_id} {--json : Machine-readable output}';

    protected $description = 'Mirror one product (and its variations) from the hub';

    public function handle(ProductSyncer $syncer): int
    {
        $id = (int) $this->argument('hub_product_id');

        try {
            $mirror = $syncer->sync($id, 'manual', (string) Str::uuid());
        } catch (Throwable $e) {
            $this->error("Failed to sync product {$id}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $result = [
            'hub_product_id' => $mirror->hub_product_id,
            'type' => $mirror->type,
            'price' => $mirror->price,
            'stock_quantity' => $mirror->stock_quantity,
            'variations' => $mirror->variations()->count(),
        ];

        $this->option('json')
            ? $this->line(json_encode($result, JSON_UNESCAPED_SLASHES))
            : $this->info("Product {$mirror->hub_product_id} ({$mirror->type}) mirrored — price {$mirror->price}, stock {$mirror->stock_quantity}, {$result['variations']} variations.");

        return self::SUCCESS;
    }
}
