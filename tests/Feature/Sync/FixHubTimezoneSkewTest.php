<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Support\Carbon;

function skewedRawOrder(int $hubOrderId): RawOrder
{
    return RawOrder::create([
        'hub_order_id' => $hubOrderId,
        'payload' => ['id' => $hubOrderId],
        'payload_hash' => hash('sha256', (string) $hubOrderId),
        'fetched_via' => 'manual',
        'received_at' => now(),
        'hub_modified_at' => Carbon::parse('2026-07-08 09:00:00', 'Asia/Tehran'), // pre-fix mislabeled value
    ]);
}

it('shifts order_date, date_paid, and hub_modified_at by +03:30', function () {
    $raw = skewedRawOrder(9001);
    $order = Order::create([
        'raw_order_id' => $raw->id, 'hub_order_id' => 9001, 'status' => 'completed',
        'order_date' => Carbon::parse('2026-07-08 09:47:32', 'Asia/Tehran'), // pre-fix mislabeled value
        'date_paid' => Carbon::parse('2026-07-08 10:00:00', 'Asia/Tehran'),
        'jalali_period' => '1405-04', 'normalized_at' => now(),
    ]);
    ProductMirror::create([
        'hub_product_id' => 8001, 'type' => 'simple', 'name' => 'X', 'payload' => [],
        'hub_modified_at' => Carbon::parse('2026-07-08 09:00:00', 'Asia/Tehran'),
    ]);

    $this->artisan('acc:fix-hub-timezone-skew')->assertSuccessful();

    expect($order->refresh()->order_date->format('Y-m-d H:i:s'))->toBe('2026-07-08 13:17:32')
        ->and($order->date_paid->format('Y-m-d H:i:s'))->toBe('2026-07-08 13:30:00')
        ->and($raw->refresh()->hub_modified_at->format('Y-m-d H:i:s'))->toBe('2026-07-08 12:30:00')
        ->and(ProductMirror::first()->hub_modified_at->format('Y-m-d H:i:s'))->toBe('2026-07-08 12:30:00');
});

it('leaves a null date_paid null', function () {
    $raw = skewedRawOrder(9002);
    $order = Order::create([
        'raw_order_id' => $raw->id, 'hub_order_id' => 9002, 'status' => 'pending',
        'order_date' => Carbon::parse('2026-07-08 09:47:32', 'Asia/Tehran'),
        'date_paid' => null, 'jalali_period' => '1405-04', 'normalized_at' => now(),
    ]);

    $this->artisan('acc:fix-hub-timezone-skew')->assertSuccessful();

    expect($order->refresh()->date_paid)->toBeNull();
});

it('recomputes jalali_period when the shift pushes an order across a jalali period boundary', function () {
    $raw = skewedRawOrder(9003);
    // 2026-07-22 22:15 (Tehran) + 3:30 = 2026-07-23 01:45 — crosses from jalali 1405-04 into 1405-05.
    $order = Order::create([
        'raw_order_id' => $raw->id, 'hub_order_id' => 9003, 'status' => 'completed',
        'order_date' => Carbon::parse('2026-07-22 22:15:00', 'Asia/Tehran'),
        'date_paid' => null, 'jalali_period' => '1405-04', 'normalized_at' => now(),
    ]);

    $this->artisan('acc:fix-hub-timezone-skew')->assertSuccessful();

    expect($order->refresh()->jalali_period)->toBe('1405-05')
        ->and($order->order_date->format('Y-m-d H:i:s'))->toBe('2026-07-23 01:45:00');
});

it('--dry-run reports counts without changing anything', function () {
    $raw = skewedRawOrder(9004);
    $order = Order::create([
        'raw_order_id' => $raw->id, 'hub_order_id' => 9004, 'status' => 'completed',
        'order_date' => Carbon::parse('2026-07-08 09:47:32', 'Asia/Tehran'),
        'date_paid' => null, 'jalali_period' => '1405-04', 'normalized_at' => now(),
    ]);

    $this->artisan('acc:fix-hub-timezone-skew --dry-run --json')
        ->expectsOutputToContain('orders_order_date')
        ->assertSuccessful();

    expect($order->refresh()->order_date->format('Y-m-d H:i:s'))->toBe('2026-07-08 09:47:32');
});
