<?php

namespace App\Domain\Costing\Services;

use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Products\Models\ProductMirror;

class CostResolver
{
    /**
     * Latest purchase cost for a product/variation via its Cost Mapping.
     * Returns null when the mapping or cost is missing — callers must treat
     * that as "unknown", NEVER as zero (README hard rule).
     */
    public function resolveFor(ProductMirror $product): ?array
    {
        $mapping = ProductCostMapping::where('product_mirror_id', $product->id)
            ->where('status', 'mapped')
            ->with('costItem')
            ->first();

        if (! $mapping?->costItem) {
            return null;
        }

        $latest = $mapping->costItem->latestCost();

        if (! $latest) {
            return null;
        }

        return [
            'unit_cost' => (int) round($latest->landed_unit_cost * $mapping->multiplier),
            'cost_item_id' => $mapping->costItem->id,
            'multiplier' => $mapping->multiplier,
            'source' => $latest->source,
            'cost_history_id' => $latest->id,
            'effective_at' => $latest->effective_at->toDateString(),
        ];
    }

    /** Pricing overview for the internal product page: retail vs wholesale profit/margins. */
    public function pricingSummary(ProductMirror $product): array
    {
        $resolved = $this->resolveFor($product);
        $cost = $resolved['unit_cost'] ?? null;

        $mapping = ProductCostMapping::where('product_mirror_id', $product->id)->with('costItem')->first();
        $wholesale = $mapping?->costItem?->latestWholesalePrice()?->price;
        $retail = $product->price;

        return [
            'latest_cost' => $cost,
            'cost_source' => $resolved['source'] ?? null,
            'retail_price' => $retail,
            'retail_profit' => ($retail !== null && $cost !== null) ? $retail - $cost : null,
            'retail_margin' => ($retail && $cost !== null) ? ($retail - $cost) / $retail * 100 : null,
            'wholesale_price' => $wholesale,
            'wholesale_profit' => ($wholesale !== null && $cost !== null) ? $wholesale - $cost : null,
            'wholesale_margin' => ($wholesale && $cost !== null) ? ($wholesale - $cost) / $wholesale * 100 : null,
            'mapping_status' => $mapping->status ?? 'unmapped',
        ];
    }
}
