<?php

use App\Domain\Accounting\Models\Setting;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Orders\Services\ProfitEngine;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Sync\Models\ReviewItem;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
    Setting::set('receivables_cutover_date', '2026-07-11');

    $this->mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $this->item = CostItem::create(['name' => 'اسپری']);
    CostHistory::create([
        'cost_item_id' => $this->item->id, 'unit_cost' => 400_000, 'landed_unit_cost' => 400_000,
        'source' => 'manual', 'effective_at' => '2026-07-01',
    ]);
    ProductCostMapping::create([
        'product_mirror_id' => $this->mirror->id, 'cost_item_id' => $this->item->id, 'status' => 'mapped',
    ]);
});

function creditOrderSyncTestOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'status' => 'completed',
        'currency' => 'IRT',
        'total' => 771000,
        'discount_total' => 10000,
        'shipping_total' => 90000,
        'created_via' => 'checkout',
        'order_source' => null, 'source_channel' => null, 'external_marketplace' => null,
        'billing' => ['first_name' => 'رضا', 'last_name' => 'احمدی', 'phone' => '09121110000'],
        'payment_method' => 'WC_Zibal',
        'date_created' => '2026-07-12T16:04:29',
        'date_modified' => '2026-07-12T16:04:29',
        'meta' => [],
        'line_items' => [[
            'id' => 91, 'name' => 'اسپری', 'quantity' => 1,
            'subtotal' => 691000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null,
        ]],
    ], $overrides);
}

it('creates an open credit order for an eligible post-cutover order', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6001, creditOrderSyncTestOrder(6001), 'manual');

    $credit = CreditOrder::where('order_id', $order->id)->first();

    expect($credit)->not->toBeNull()
        ->and($credit->status)->toBe('open')
        ->and($credit->total_due)->toBe($order->profit->net_sale + $order->profit->shipping_charged)
        ->and($credit->party_id)->toBe($order->customer_party_id);
});

it('never tracks a basalam order — the channel already settles with the store directly', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6002, creditOrderSyncTestOrder(6002, [
        'order_source' => 'basalam',
        'meta' => ['_basalam_fee_amount' => '50000', '_basalam_balance_amount' => '631000'],
    ]), 'manual');

    expect($order->channel?->slug)->toBe('basalam')
        ->and(CreditOrder::where('order_id', $order->id)->exists())->toBeFalse();
});

it('never tracks an order dated before the receivables cutover', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6003, creditOrderSyncTestOrder(6003, [
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00',
    ]), 'manual');

    expect(CreditOrder::where('order_id', $order->id)->exists())->toBeFalse();
});

it('never tracks an order already marked paid from real hub/gateway data', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6004, creditOrderSyncTestOrder(6004, [
        'date_paid' => '2026-07-12T16:10:00',
    ]), 'manual');

    expect($order->payment_status)->toBe('paid')
        ->and(CreditOrder::where('order_id', $order->id)->exists())->toBeFalse();
});

it('keeps total_due in sync on recalculation without touching paid_total', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6005, creditOrderSyncTestOrder(6005), 'manual');
    $credit = CreditOrder::where('order_id', $order->id)->first();
    $credit->update(['paid_total' => 100000]);

    app(ProfitEngine::class)->recalculate($order->fresh(), force: true);

    expect($credit->refresh()->paid_total)->toBe(100000)
        ->and($credit->total_due)->toBe($order->profit->net_sale + $order->profit->shipping_charged);
});

it('flags for review instead of silently reconciling when a recalculation drops total_due below what was already paid', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6006, creditOrderSyncTestOrder(6006), 'manual');
    $credit = CreditOrder::where('order_id', $order->id)->first();
    $credit->update(['paid_total' => $credit->total_due]); // fully paid at today's total_due

    // Simulate a correction that lowers the order's recognized sale (e.g. a shipping override).
    app(ProfitEngine::class)->recalculate($order->fresh(), force: true);
    $credit->update(['total_due' => $credit->total_due + 50000]); // now behaves as if paid_total < total_due again
    $credit->update(['paid_total' => $credit->total_due + 1]); // force an overpaid state

    app(ProfitEngine::class)->recalculate($order->fresh(), force: true);

    expect(ReviewItem::where('type', 'credit_order_overpaid_after_recalc')->where('subject_id', $credit->id)->count())->toBe(1);
});

it('deletes the credit order for a cancelled order that never received any payment', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6007, creditOrderSyncTestOrder(6007), 'manual');
    $creditId = CreditOrder::where('order_id', $order->id)->value('id');

    $order->update(['status' => 'cancelled']);
    app(OrderIngestPipeline::class)->ingest(6007, creditOrderSyncTestOrder(6007, ['status' => 'cancelled']), 'manual');

    expect(CreditOrder::find($creditId))->toBeNull();
});

it('flags for review instead of deleting when a cancelled order already received payment', function () {
    $order = app(OrderIngestPipeline::class)->ingest(6008, creditOrderSyncTestOrder(6008), 'manual');
    $credit = CreditOrder::where('order_id', $order->id)->first();
    $credit->update(['paid_total' => 100000]);

    app(OrderIngestPipeline::class)->ingest(6008, creditOrderSyncTestOrder(6008, ['status' => 'cancelled']), 'manual');

    expect($credit->refresh()->status)->toBe('settled')
        ->and(ReviewItem::where('type', 'credit_order_reversed_with_payments')->where('subject_id', $credit->id)->count())->toBe(1);
});
