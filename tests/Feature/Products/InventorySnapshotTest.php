<?php

use App\Domain\Products\Models\InventorySnapshot;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\InventorySnapshotService;

it('sums exact on-hand units and their value at selling price, across simple and variation items only', function () {
    ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'ساده', 'price' => 100_000, 'stock_quantity' => 10, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 2, 'type' => 'variable', 'name' => 'والد متغیر', 'stock_quantity' => null, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 3, 'parent_hub_id' => 2, 'type' => 'variation', 'name' => 'تنوع ۱', 'price' => 50_000, 'stock_quantity' => 4, 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 4, 'parent_hub_id' => 2, 'type' => 'variation', 'name' => 'تنوع ۲', 'price' => 60_000, 'stock_quantity' => 6, 'payload' => []]);
    // Trashed stock must not count.
    ProductMirror::create(['hub_product_id' => 5, 'type' => 'simple', 'name' => 'حذف‌شده', 'status' => 'trash', 'price' => 999_999, 'stock_quantity' => 100, 'payload' => []]);

    $snapshot = app(InventorySnapshotService::class)->refresh();

    expect($snapshot->total_units)->toBe(20) // 10 + 4 + 6, variable parent excluded, trashed excluded
        ->and($snapshot->total_value)->toBe(10 * 100_000 + 4 * 50_000 + 6 * 60_000);
});

it('falls back to regular_price when the current selling price is not set', function () {
    ProductMirror::create(['hub_product_id' => 11, 'type' => 'simple', 'name' => 'بدون قیمت فعلی', 'price' => null, 'regular_price' => 80_000, 'stock_quantity' => 5, 'payload' => []]);

    $snapshot = app(InventorySnapshotService::class)->refresh();

    expect($snapshot->total_value)->toBe(5 * 80_000);
});

it('values by our selling price, not by purchase/landed cost', function () {
    ProductMirror::create(['hub_product_id' => 21, 'type' => 'simple', 'name' => 'کالا', 'price' => 120_000, 'stock_quantity' => 2, 'payload' => []]);

    $snapshot = app(InventorySnapshotService::class)->refresh();

    expect($snapshot->total_value)->toBe(240_000);
});

it('keeps every computed run as its own row so future reports can chart it over time, and latest() returns the newest', function () {
    $service = app(InventorySnapshotService::class);
    $service->refresh();
    ProductMirror::create(['hub_product_id' => 31, 'type' => 'simple', 'name' => 'کالای جدید', 'price' => 10_000, 'stock_quantity' => 1, 'payload' => []]);
    $second = $service->refresh();

    expect(InventorySnapshot::count())->toBe(2)
        ->and($service->latest()->id)->toBe($second->id);
});

it('the artisan command computes and stores a snapshot', function () {
    ProductMirror::create(['hub_product_id' => 41, 'type' => 'simple', 'name' => 'کالا', 'price' => 5_000, 'stock_quantity' => 3, 'payload' => []]);

    $this->artisan('acc:products:snapshot-inventory')->assertSuccessful();

    expect(InventorySnapshot::count())->toBe(1)
        ->and(InventorySnapshot::first()->total_units)->toBe(3);
});
