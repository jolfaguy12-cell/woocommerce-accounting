<?php

use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
});

function normalizerHubOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'status' => 'pending',
        'currency' => 'IRT',
        'total' => 200000,
        'discount_total' => 0,
        'shipping_total' => 0,
        'created_via' => 'checkout',
        'order_source' => null, 'source_channel' => null, 'external_marketplace' => null,
        'payment_method' => 'WC_Zibal',
        'date_created' => '2026-07-08T16:04:29',
        'date_modified' => '2026-07-08T16:04:29',
        'meta' => [],
        'line_items' => [
            ['id' => 1, 'name' => 'آیتم یک', 'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'product_id' => 111, 'variation_id' => null],
            ['id' => 2, 'name' => 'آیتم دو', 'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'product_id' => 222, 'variation_id' => null],
        ],
    ], $overrides);
}

it('removes order items that were deleted upstream in a WooCommerce edit, not just adds new ones', function () {
    $pipeline = app(OrderIngestPipeline::class);

    $order = $pipeline->ingest(2001, normalizerHubOrder(2001), 'manual');
    expect($order->items()->pluck('hub_item_id')->sort()->values()->all())->toBe([1, 2]);

    // Order edited in WooCommerce: item 2 removed, item 3 added.
    $edited = normalizerHubOrder(2001);
    $edited['line_items'] = [
        $edited['line_items'][0],
        ['id' => 3, 'name' => 'آیتم سه', 'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'product_id' => 333, 'variation_id' => null],
    ];
    $edited['date_modified'] = '2026-07-08T16:10:00';

    $order = $pipeline->ingest(2001, $edited, 'manual');

    expect($order->items()->pluck('hub_item_id')->sort()->values()->all())->toBe([1, 3]);
});

it('reads channel-source meta from WooCommerce raw meta_data shape, not just the hub-normalized meta map', function () {
    $orderData = normalizerHubOrder(2002, ['created_via' => null]);
    unset($orderData['meta']);
    $orderData['meta_data'] = [
        ['key' => '_wc_order_attribution_source_type', 'value' => 'admin'],
    ];

    app(OrderIngestPipeline::class)->ingest(2002, $orderData, 'manual');

    $order = Order::firstWhere('hub_order_id', 2002);

    expect($order->raw_source_value)->toBe('admin')
        ->and(ChannelSource::where('raw_value', 'admin')->exists())->toBeTrue();
});
