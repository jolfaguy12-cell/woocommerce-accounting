<?php

use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Costing\Models\WholesalePrice;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Products\Models\ProductMirror;

beforeEach(function () {
    $this->item = CostItem::create(['name' => 'اسپری']);
    $this->product = ProductMirror::create([
        'hub_product_id' => 900, 'type' => 'simple', 'name' => 'اسپری رکسونا',
        'price' => 771_000, 'payload' => [],
    ]);
});

function cost(CostItem $item, int $landed, string $effective): CostHistory
{
    return CostHistory::create([
        'cost_item_id' => $item->id, 'unit_cost' => $landed, 'landed_unit_cost' => $landed,
        'source' => 'manual', 'effective_at' => $effective,
    ]);
}

it('resolves the latest cost for a mapped product with multiplier applied', function () {
    cost($this->item, 480_000, '2026-06-01');
    cost($this->item, 510_000, '2026-07-01'); // latest

    ProductCostMapping::create([
        'product_mirror_id' => $this->product->id,
        'cost_item_id' => $this->item->id,
        'multiplier' => 2, // pack of two
        'status' => 'mapped',
    ]);

    $resolved = app(CostResolver::class)->resolveFor($this->product);

    expect($resolved)->not->toBeNull()
        ->and($resolved['unit_cost'])->toBe(1_020_000)
        ->and($resolved['cost_item_id'])->toBe($this->item->id)
        ->and($resolved['source'])->toBe('manual');
});

it('returns null (never zero) for unmapped products or mapped items without cost', function () {
    expect(app(CostResolver::class)->resolveFor($this->product))->toBeNull();

    ProductCostMapping::create([
        'product_mirror_id' => $this->product->id,
        'cost_item_id' => $this->item->id,
        'status' => 'mapped',
    ]);

    expect(app(CostResolver::class)->resolveFor($this->product))->toBeNull(); // no cost history yet
});

it('computes retail and wholesale profit and margins', function () {
    cost($this->item, 500_000, '2026-07-01');
    ProductCostMapping::create([
        'product_mirror_id' => $this->product->id,
        'cost_item_id' => $this->item->id, 'status' => 'mapped',
    ]);
    WholesalePrice::create(['cost_item_id' => $this->item->id, 'price' => 650_000, 'effective_at' => '2026-07-01']);

    $summary = app(CostResolver::class)->pricingSummary($this->product);

    expect($summary['latest_cost'])->toBe(500_000)
        ->and($summary['retail_price'])->toBe(771_000)
        ->and($summary['retail_profit'])->toBe(271_000)
        ->and($summary['wholesale_price'])->toBe(650_000)
        ->and($summary['wholesale_profit'])->toBe(150_000)
        ->and(round($summary['wholesale_margin'], 2))->toBe(23.08); // 150000/650000
});
