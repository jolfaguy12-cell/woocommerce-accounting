<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Channels\Services\ChannelMapper;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\ReviewItem;
use Database\Seeders\ChannelSeeder;

beforeEach(function () {
    $this->seed(ChannelSeeder::class);
    $this->pipeline = app(OrderIngestPipeline::class);
});

/** Shaped like a real hub order (see live order 6591). */
function basalamOrder(int $id = 6591, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'status' => 'bslm-preparation',
        'currency' => 'IRT',
        'total' => 771000,
        'discount_total' => 0,
        'shipping_total' => 90000,
        'created_via' => null,
        'order_source' => 'basalam',
        'source_channel' => 'basalam',
        'external_marketplace' => 'basalam',
        'external_order_id' => 'PZ6PmK',
        'payment_method' => 'basalam payment method',
        'payment_method_title' => 'Basalam Payment',
        'transaction_id' => '',
        'customer_id' => 0,
        'date_created' => '2026-07-08T16:04:29',
        'date_modified' => '2026-07-08T16:04:29',
        'meta' => ['_sync_basalam_hash_id' => 'PZ6PmK', '_basalam_fee_amount' => '-81720'],
        'line_items' => [[
            'id' => 5564, 'name' => 'اسپری رکسونا', 'quantity' => 1,
            'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null,
        ]],
    ], $overrides);
}

it('normalizes a hub order into Toman integers with a jalali period and resolved channel', function () {
    ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);

    $this->pipeline->ingest(6591, basalamOrder(), 'manual');

    $order = Order::firstWhere('hub_order_id', 6591);

    expect($order)->not->toBeNull()
        ->and($order->total)->toBe(771000)
        ->and($order->shipping_charged)->toBe(90000)
        ->and($order->jalali_period)->toBe('1405-04')
        ->and($order->raw_source_value)->toBe('basalam')
        ->and($order->channel->slug)->toBe('basalam')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->product_mirror_id)->not->toBeNull();
});

it('re-ingesting the same order updates in place without duplicates', function () {
    $this->pipeline->ingest(6591, basalamOrder(), 'manual');
    $this->pipeline->ingest(6591, basalamOrder(6591, ['status' => 'completed', 'date_modified' => '2026-07-09T10:00:00']), 'poll');

    expect(Order::count())->toBe(1)
        ->and(Order::first()->status)->toBe('completed')
        ->and(Order::first()->items)->toHaveCount(1);
});

it('never fails on an unknown source like gemini — stores, queues for review, keeps processing', function () {
    $order = basalamOrder(7000, [
        'order_source' => 'gemeni', // typo'd future source
        'source_channel' => null, 'external_marketplace' => null, 'meta' => [],
    ]);

    $this->pipeline->ingest(7000, $order, 'webhook');
    $this->pipeline->ingest(7001, basalamOrder(7001, ['order_source' => 'gemeni', 'source_channel' => null, 'external_marketplace' => null, 'meta' => []]), 'webhook');

    $normalized = Order::firstWhere('hub_order_id', 7000);
    $source = ChannelSource::firstWhere('raw_value', 'gemeni');

    expect($normalized->channel_id)->toBeNull()
        ->and($normalized->profit_status)->toBe('unknown_source')
        ->and($source->status)->toBe('unknown')
        ->and($source->order_count)->toBe(2)
        ->and(ReviewItem::where('type', 'unknown_source')->count())->toBe(1); // one per source, not per order
});

it('falls back to created_via for plain website orders', function () {
    $order = basalamOrder(7100, [
        'order_source' => null, 'source_channel' => null, 'external_marketplace' => null,
        'created_via' => 'checkout', 'meta' => [],
    ]);

    $this->pipeline->ingest(7100, $order, 'webhook');

    expect(Order::firstWhere('hub_order_id', 7100)->channel->slug)->toBe('website');
});

it('mapping an unknown source to a channel reclassifies its existing orders', function () {
    $gemini = basalamOrder(7200, ['order_source' => 'gemini', 'source_channel' => null, 'external_marketplace' => null, 'meta' => []]);
    $this->pipeline->ingest(7200, $gemini, 'webhook');

    $source = ChannelSource::firstWhere('raw_value', 'gemini');
    $channel = Channel::create(['name' => 'جمینی', 'slug' => 'gemini', 'cost_model' => 'none', 'valid_statuses' => ['completed']]);

    app(ChannelMapper::class)->map($source, $channel);

    expect($source->refresh()->status)->toBe('mapped')
        ->and(Order::firstWhere('hub_order_id', 7200)->channel_id)->toBe($channel->id)
        ->and(Order::firstWhere('hub_order_id', 7200)->profit_status)->not->toBe('unknown_source')
        ->and(ReviewItem::where('type', 'unknown_source')->where('status', 'open')->count())->toBe(0);
});
