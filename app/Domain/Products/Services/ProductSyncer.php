<?php

namespace App\Domain\Products\Services;

use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Services\HubClient;
use Illuminate\Support\Carbon;

class ProductSyncer
{
    public function __construct(private readonly HubClient $hub) {}

    /** Fetch one product (and its variations, when variable) from the hub and mirror it. */
    public function sync(int $hubProductId, string $via, ?string $correlationId = null): ProductMirror
    {
        $detail = $this->hub->product($hubProductId);
        $variationIds = array_filter((array) ($detail['variations'] ?? []));
        $type = $variationIds === [] ? 'simple' : 'variable';

        $mirror = $this->upsert($detail, $type, $via, $correlationId);

        if ($type === 'variable') {
            foreach ($this->hub->productVariations($hubProductId) as $variation) {
                if (is_array($variation) && isset($variation['id'])) {
                    $this->upsert($variation, 'variation', $via, $correlationId);
                }
            }
        }

        return $mirror;
    }

    public function upsert(array $payload, string $type, string $via, ?string $correlationId = null): ProductMirror
    {
        $attributes = [
            'parent_hub_id' => ($payload['parent_id'] ?? 0) ?: null,
            'type' => $type,
            'name' => $payload['name'] ?? '',
            'sku' => $payload['sku'] ?: null,
            'gtin' => ($payload['global_unique_id'] ?? '') ?: null,
            'status' => $payload['status'] ?? null,
            'price' => $this->toman($payload['price'] ?? null),
            'regular_price' => $this->toman($payload['regular_price'] ?? null),
            'sale_price' => $this->toman($payload['sale_price'] ?? null),
            'stock_quantity' => isset($payload['stock_quantity']) ? (int) $payload['stock_quantity'] : null,
            'stock_status' => $payload['stock_status'] ?? null,
            // Hub weight unit is grams (confirmed against real store data), no conversion needed.
            'weight_grams' => isset($payload['weight']) ? (int) round((float) $payload['weight']) : null,
            'payload' => $payload,
            'hub_modified_at' => isset($payload['date_modified']) ? Carbon::parse($payload['date_modified'], 'UTC') : null,
        ];

        $existing = ProductMirror::firstWhere('hub_product_id', $payload['id']);

        if (! $existing) {
            return ProductMirror::create(['hub_product_id' => $payload['id']] + $attributes);
        }

        $this->recordHistories($existing, $attributes, $via, $correlationId);
        $existing->update($attributes);

        return $existing;
    }

    private function recordHistories(ProductMirror $existing, array $new, string $via, ?string $correlationId): void
    {
        if ($existing->price !== $new['price']) {
            $existing->priceHistory()->create([
                'old_price' => $existing->price,
                'new_price' => $new['price'],
                'source' => $via,
                'correlation_id' => $correlationId,
                'changed_at' => now(),
            ]);
        }

        if ($existing->stock_quantity !== $new['stock_quantity']) {
            $existing->stockHistory()->create([
                'old_quantity' => $existing->stock_quantity,
                'new_quantity' => $new['stock_quantity'],
                'source' => $via,
                'correlation_id' => $correlationId,
                'changed_at' => now(),
            ]);
        }
    }

    private function toman(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Hub amounts are already Toman (divisor=1 confirmed with the business).
        return (int) round((float) $value / max(1, (int) config('accounting.currency_divisor', 1)));
    }
}
