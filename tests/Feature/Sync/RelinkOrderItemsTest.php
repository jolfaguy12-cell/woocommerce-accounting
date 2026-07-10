<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\RawOrder;

function makeOrderWithItem(int $hubOrderId, int $hubProductId, ?int $productMirrorId): OrderItem
{
    $raw = RawOrder::create([
        'hub_order_id' => $hubOrderId,
        'payload' => ['id' => $hubOrderId],
        'payload_hash' => hash('sha256', (string) $hubOrderId),
        'fetched_via' => 'manual',
        'received_at' => now(),
    ]);

    $order = Order::create([
        'raw_order_id' => $raw->id,
        'hub_order_id' => $hubOrderId,
        'status' => 'completed',
        'order_date' => now(),
        'jalali_period' => '1405-04',
        'normalized_at' => now(),
    ]);

    return $order->items()->create([
        'hub_item_id' => 1,
        'hub_product_id' => $hubProductId,
        'product_mirror_id' => $productMirrorId,
        'name' => "Product {$hubProductId}",
        'qty' => 1,
    ]);
}

it('acc:sync:relink-order-items links items whose product only synced later', function () {
    $item = makeOrderWithItem(hubOrderId: 9401, hubProductId: 7001, productMirrorId: null);

    // Product didn't exist at normalization time; it does now (e.g. after a backfill).
    $mirror = ProductMirror::create(['hub_product_id' => 7001, 'type' => 'simple', 'name' => 'X', 'payload' => []]);

    $this->artisan('acc:sync:relink-order-items')->assertSuccessful();

    expect($item->fresh()->product_mirror_id)->toBe($mirror->id);
});

it('acc:sync:relink-order-items prefers the variation id, matching OrderNormalizer', function () {
    $item = makeOrderWithItem(hubOrderId: 9402, hubProductId: 7002, productMirrorId: null);
    $item->update(['hub_variation_id' => 7003]);

    ProductMirror::create(['hub_product_id' => 7002, 'type' => 'variable', 'name' => 'Parent', 'payload' => []]);
    $variation = ProductMirror::create(['hub_product_id' => 7003, 'parent_hub_id' => 7002, 'type' => 'variation', 'name' => 'Variation', 'payload' => []]);

    $this->artisan('acc:sync:relink-order-items')->assertSuccessful();

    expect($item->fresh()->product_mirror_id)->toBe($variation->id);
});

it('acc:sync:relink-order-items leaves items unlinked when their product still has no mirror', function () {
    $item = makeOrderWithItem(hubOrderId: 9403, hubProductId: 7004, productMirrorId: null);

    $this->artisan('acc:sync:relink-order-items')->assertSuccessful();

    expect($item->fresh()->product_mirror_id)->toBeNull();
});

it('acc:sync:relink-order-items --dry-run reports counts without updating', function () {
    $item = makeOrderWithItem(hubOrderId: 9404, hubProductId: 7005, productMirrorId: null);
    ProductMirror::create(['hub_product_id' => 7005, 'type' => 'simple', 'name' => 'Y', 'payload' => []]);

    $this->artisan('acc:sync:relink-order-items --dry-run --json')
        ->expectsOutputToContain('now_linkable')
        ->assertSuccessful();

    expect($item->fresh()->product_mirror_id)->toBeNull();
});

it('acc:sync:relink-order-items never touches items that are already linked', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 7006, 'type' => 'simple', 'name' => 'Z', 'payload' => []]);
    ProductMirror::create(['hub_product_id' => 7007, 'type' => 'simple', 'name' => 'Z2', 'payload' => []]);
    $item = makeOrderWithItem(hubOrderId: 9405, hubProductId: 7007, productMirrorId: $mirror->id);

    $this->artisan('acc:sync:relink-order-items')->assertSuccessful();

    expect($item->fresh()->product_mirror_id)->toBe($mirror->id);
});
