<?php

namespace App\Domain\Costing\Services;

use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Products\Models\ProductMirror;

/**
 * Every product needs a Cost Item to hang cost history off of, but users never
 * pick or name one directly (that indirection confused more than it helped) —
 * the first time a cost/wholesale/purchase entry touches a product, silently
 * create its own 1:1 Cost Item (multiplier 1) if it doesn't already have one.
 */
class ProductMappingResolver
{
    public function resolveOrCreate(ProductMirror $product): ProductCostMapping
    {
        if ($mapping = $product->costMapping) {
            return $mapping;
        }

        $item = CostItem::create(['name' => $product->name, 'sku' => $product->sku]);

        return ProductCostMapping::create([
            'product_mirror_id' => $product->id,
            'cost_item_id' => $item->id,
            'multiplier' => 1,
            'status' => 'mapped',
        ]);
    }
}
