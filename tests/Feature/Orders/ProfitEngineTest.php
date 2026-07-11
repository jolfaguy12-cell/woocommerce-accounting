<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PackagingCostTier;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderPackagingCost;
use App\Domain\Orders\Models\OrderProfit;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Orders\Services\ProfitEngine;
use App\Domain\Orders\Services\RefundRecorder;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\ReviewItem;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);

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

function hubOrder(int $id, array $overrides = []): array
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
        'payment_method' => 'WC_Zibal',
        'date_created' => '2026-07-08T16:04:29',
        'date_modified' => '2026-07-08T16:04:29',
        'meta' => [],
        'line_items' => [[
            'id' => 91, 'name' => 'اسپری', 'quantity' => 1,
            'subtotal' => 691000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null,
        ]],
    ], $overrides);
}

it('posts an explainable, balanced profit entry for a completed mapped order', function () {
    $order = app(OrderIngestPipeline::class)->ingest(1001, hubOrder(1001), 'manual');

    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($order->refresh()->financial_state)->toBe('valid')
        ->and($order->profit_status)->toBe('ok')
        ->and($profit->status)->toBe('final')
        ->and($profit->gross_sale)->toBe(691_000)
        ->and($profit->discounts)->toBe(10_000)
        ->and($profit->net_sale)->toBe(681_000)
        ->and($profit->product_cost)->toBe(400_000)
        ->and($profit->shipping_charged)->toBe(90_000)
        ->and($profit->shipping_real)->toBe(90_000) // customer-paid fallback
        ->and($profit->shipping_basis)->toBe('customer_paid')
        ->and($profit->gross_profit)->toBe(281_000)
        ->and($profit->operational_profit)->toBe(281_000) // charged == real, fee 0
        ->and($profit->cost_breakdown)->toHaveCount(1);

    $entry = $profit->journalEntry;
    expect($entry)->not->toBeNull()
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'));
});

it('excludes pending-payment orders from profit', function () {
    app(OrderIngestPipeline::class)->ingest(1002, hubOrder(1002, ['status' => 'pending']), 'manual');

    $order = Order::firstWhere('hub_order_id', 1002);
    expect($order->financial_state)->toBe('pending')
        ->and(OrderProfit::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('blocks profit on missing cost — never zero — and opens a review item', function () {
    $orderData = hubOrder(1003);
    $orderData['line_items'][0]['product_id'] = 9999; // no mirror, no mapping

    app(OrderIngestPipeline::class)->ingest(1003, $orderData, 'manual');

    $order = Order::firstWhere('hub_order_id', 1003);
    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($order->profit_status)->toBe('blocked_missing_cost')
        ->and($profit->status)->toBe('blocked')
        ->and($profit->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe(0)
        ->and(ReviewItem::where('type', 'missing_cost')->count())->toBe(1);
});

it('uses the default shipping setting when no real cost and customer shipping is zero', function () {
    Setting::set('default_shipping_cost', 65_000);

    app(OrderIngestPipeline::class)->ingest(1004, hubOrder(1004, ['shipping_total' => 0, 'total' => 681000]), 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1004)->id);

    expect($profit->shipping_real)->toBe(65_000)
        ->and($profit->shipping_basis)->toBe('default')
        ->and($profit->operational_profit)->toBe(281_000 - 65_000);
});

it('reads basalam commission from order metadata', function () {
    $order = hubOrder(1005, [
        'order_source' => 'basalam', 'status' => 'bslm-completed',
        'meta' => ['_sync_basalam_hash_id' => 'X', '_basalam_fee_amount' => '-81720'],
    ]);

    app(OrderIngestPipeline::class)->ingest(1005, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1005)->id);

    expect($profit->channel_fee)->toBe(81_720)
        ->and($profit->status)->toBe('final')
        ->and($profit->operational_profit)->toBe(281_000 - 81_720);
});

it('derives a marketplace discount from basalam settlement metadata (a coupon woocommerce never saw)', function () {
    $order = hubOrder(1007, [
        'order_source' => 'basalam', 'status' => 'bslm-completed', 'discount_total' => 0,
        'meta' => [
            '_sync_basalam_hash_id' => 'X',
            '_basalam_fee_amount' => '-81720',
            // gross(691000) - fee(81720) - balance(509280) = 100,000 discount
            '_basalam_balance_amount' => '509280',
        ],
    ]);

    app(OrderIngestPipeline::class)->ingest(1007, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1007)->id);

    expect($profit->channel_discount)->toBe(100_000)
        ->and($profit->channel_discount_source)->toBe('metadata')
        // hubOrder()'s default line item already has a 10,000 gap between subtotal
        // and total (a native woo-level discount) — this adds on top of that.
        ->and($profit->discounts)->toBe(10_000 + 100_000)
        ->and($profit->net_sale)->toBe(681_000 - 100_000)
        ->and($profit->status)->toBe('final');

    $entry = $profit->journalEntry;
    $revenueLine = $entry->lines->first(fn ($l) => $l->account->code === '4000');
    expect($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'))
        ->and($revenueLine->credit)->toBe($profit->net_sale);
});

it('treats a literal-zero commission as missing, not a real 100%-off coupon', function () {
    // Real production case: Basalam hadn't settled this order yet, so both
    // meta values were literally "0" — not an actual $0 commission and $0
    // payout. Reading that at face value would derive a "discount" equal to
    // the entire order (items_total - 0 - 0), which is nonsense.
    $order = hubOrder(1009, [
        'order_source' => 'basalam', 'status' => 'bslm-completed',
        'meta' => [
            '_sync_basalam_hash_id' => 'X',
            '_basalam_fee_amount' => '0',
            '_basalam_balance_amount' => '0',
        ],
    ]);

    app(OrderIngestPipeline::class)->ingest(1009, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1009)->id);

    expect($profit->channel_fee)->toBe(0)
        ->and($profit->channel_fee_source)->toBe('none')
        ->and($profit->channel_discount)->toBe(0)
        ->and($profit->channel_discount_source)->toBe('none')
        ->and($profit->status)->toBe('provisional')
        ->and(ReviewItem::where('type', 'missing_commission')->count())->toBe(1);
});

it('does not derive a discount when the balance is present but the commission is still unconfirmed', function () {
    // A real settlement balance with a literal-zero (unsettled) commission —
    // the gap can't be trusted as a genuine discount since we don't actually
    // know the real commission yet.
    $order = hubOrder(1010, [
        'order_source' => 'basalam', 'status' => 'bslm-completed',
        'meta' => [
            '_sync_basalam_hash_id' => 'X',
            '_basalam_fee_amount' => '0',
            '_basalam_balance_amount' => '550000',
        ],
    ]);

    app(OrderIngestPipeline::class)->ingest(1010, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1010)->id);

    expect($profit->channel_discount)->toBe(0)
        ->and($profit->channel_discount_source)->toBe('none');
});

it('ignores sub-100-toman noise in the balance metadata as a real discount', function () {
    $order = hubOrder(1008, [
        'order_source' => 'basalam', 'status' => 'bslm-completed',
        'meta' => [
            '_sync_basalam_hash_id' => 'X',
            '_basalam_fee_amount' => '-81720',
            // gross(691000) - fee(81720) - balance(609260) = 20 toman of rounding noise
            '_basalam_balance_amount' => '609260',
        ],
    ]);

    app(OrderIngestPipeline::class)->ingest(1008, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1008)->id);

    expect($profit->channel_discount)->toBe(0)
        ->and($profit->channel_discount_source)->toBe('none');
});

it('warns (not crashes) when a commission channel order lacks commission metadata', function () {
    $order = hubOrder(1006, ['order_source' => 'basalam', 'status' => 'bslm-completed', 'meta' => []]);

    app(OrderIngestPipeline::class)->ingest(1006, $order, 'manual');

    $profit = OrderProfit::firstWhere('order_id', Order::firstWhere('hub_order_id', 1006)->id);

    expect($profit->status)->toBe('provisional')
        ->and($profit->channel_fee)->toBe(0)
        ->and($profit->journal_entry_id)->not->toBeNull() // still posts, flagged for review
        ->and(ReviewItem::where('type', 'missing_commission')->count())->toBe(1);
});

it('reverses posted profit when a completed order regresses to a non-valid status', function () {
    app(OrderIngestPipeline::class)->ingest(1007, hubOrder(1007), 'manual');
    $entryId = OrderProfit::first()->journal_entry_id;

    app(OrderIngestPipeline::class)->ingest(1007, hubOrder(1007, ['status' => 'cancelled', 'date_modified' => '2026-07-09T10:00:00']), 'manual');

    $order = Order::firstWhere('hub_order_id', 1007);
    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($order->financial_state)->toBe('cancelled')
        ->and(JournalEntry::find($entryId)->status)->toBe('reversed')
        ->and($profit->status)->toBe('reversed');
});

it('recalculates with a new version after costs change (reverse + repost)', function () {
    $order = app(OrderIngestPipeline::class)->ingest(1008, hubOrder(1008), 'manual');
    $v1 = OrderProfit::firstWhere('order_id', $order->id);
    $v1EntryId = $v1->journal_entry_id;

    CostHistory::create([
        'cost_item_id' => $this->item->id, 'unit_cost' => 450_000, 'landed_unit_cost' => 450_000,
        'source' => 'manual', 'effective_at' => '2026-07-08',
    ]);

    app(ProfitEngine::class)->recalculate($order->refresh());

    $profit = OrderProfit::firstWhere('order_id', $order->id);
    expect($profit->version)->toBe(2)
        ->and($profit->product_cost)->toBe(450_000)
        ->and(JournalEntry::find($v1EntryId)->status)->toBe('reversed');
});

it('recalculation with unchanged inputs does not bump the version', function () {
    $order = app(OrderIngestPipeline::class)->ingest(1009, hubOrder(1009), 'manual');

    app(ProfitEngine::class)->recalculate($order->refresh());
    app(ProfitEngine::class)->recalculate($order->refresh());

    expect(OrderProfit::firstWhere('order_id', $order->id)->version)->toBe(1)
        ->and(JournalEntry::where('status', 'posted')->count())->toBe(1);
});

it('recognizes profit for a completed or shipping basalam order but not one still in preparation', function () {
    app(OrderIngestPipeline::class)->ingest(1011, hubOrder(1011, ['order_source' => 'basalam', 'status' => 'bslm-completed', 'meta' => ['_basalam_fee_amount' => '-1000']]), 'manual');
    app(OrderIngestPipeline::class)->ingest(1012, hubOrder(1012, ['order_source' => 'basalam', 'status' => 'bslm-shipping', 'meta' => ['_basalam_fee_amount' => '-1000']]), 'manual');
    app(OrderIngestPipeline::class)->ingest(1013, hubOrder(1013, ['order_source' => 'basalam', 'status' => 'bslm-preparation', 'meta' => ['_basalam_fee_amount' => '-1000']]), 'manual');

    expect(Order::firstWhere('hub_order_id', 1011)->financial_state)->toBe('valid')
        ->and(Order::firstWhere('hub_order_id', 1012)->financial_state)->toBe('valid')
        ->and(Order::firstWhere('hub_order_id', 1013)->financial_state)->toBe('pending')
        ->and(OrderProfit::whereIn('order_id', Order::whereIn('hub_order_id', [1011, 1012])->pluck('id'))->count())->toBe(2)
        ->and(OrderProfit::where('order_id', Order::firstWhere('hub_order_id', 1013)->id)->exists())->toBeFalse();
});

it('posts a proportional reversal for a partial refund', function () {
    $order = app(OrderIngestPipeline::class)->ingest(1010, hubOrder(1010), 'manual');

    app(RefundRecorder::class)->record($order->refresh(), 340_500, 'مرجوعی نصف سفارش'); // 50% of net sale

    $order->refresh();
    $refundEntry = JournalEntry::where('idempotency_key', 'like', 'order:1010:refund:%')->first();

    expect($order->financial_state)->toBe('partially_refunded')
        ->and($refundEntry)->not->toBeNull()
        ->and($refundEntry->lines->sum('debit'))->toBe($refundEntry->lines->sum('credit'))
        ->and($refundEntry->lines->sum('debit'))->toBeGreaterThan(0);
});

it('falls back to the flat default packaging cost when no tier matches the weight', function () {
    // Mirror has no weight_grams set -> falls back to default_product_weight_grams (150g) x qty 1,
    // plus default_packaging_weight_grams (100g) = 250g, below any tier.
    $order = app(OrderIngestPipeline::class)->ingest(1101, hubOrder(1101), 'manual');
    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($profit->package_weight_grams)->toBe(250)
        ->and($profit->packaging_cost)->toBe(12_000)
        ->and($profit->packaging_cost_basis)->toBe('default')
        // Tracked only — must not change the already-verified operational profit.
        ->and($profit->operational_profit)->toBe(281_000);
});

it('resolves the highest matching weight tier for the packaging cost', function () {
    PackagingCostTier::create(['min_weight_grams' => 1000, 'cost' => 20_000]);
    PackagingCostTier::create(['min_weight_grams' => 2000, 'cost' => 30_000]);
    PackagingCostTier::create(['min_weight_grams' => 3000, 'cost' => 50_000]);
    $this->mirror->update(['weight_grams' => 2000]); // + 100g packaging = 2100g -> the 2000g tier

    $order = app(OrderIngestPipeline::class)->ingest(1102, hubOrder(1102), 'manual');
    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($profit->package_weight_grams)->toBe(2100)
        ->and($profit->packaging_cost)->toBe(30_000)
        ->and($profit->packaging_cost_basis)->toBe('tier');
});

it('a manual per-order packaging cost override wins over any tier or default', function () {
    $order = app(OrderIngestPipeline::class)->ingest(1103, hubOrder(1103), 'manual');
    OrderPackagingCost::create(['order_id' => $order->id, 'real_cost' => 77_000]);

    app(ProfitEngine::class)->recalculate($order->refresh());
    $profit = OrderProfit::firstWhere('order_id', $order->id);

    expect($profit->packaging_cost)->toBe(77_000)
        ->and($profit->packaging_cost_basis)->toBe('manual');
});

it('packaging cost is resolved once and does not silently change when tiers are edited later', function () {
    PackagingCostTier::create(['min_weight_grams' => 0, 'cost' => 5_000]);

    $order = app(OrderIngestPipeline::class)->ingest(1104, hubOrder(1104), 'manual');
    $before = OrderProfit::firstWhere('order_id', $order->id)->packaging_cost;

    PackagingCostTier::first()->update(['cost' => 999_999]); // change settings after the fact

    $stillFrozen = OrderProfit::firstWhere('order_id', $order->id)->packaging_cost;

    expect($before)->toBe(5_000)
        ->and($stillFrozen)->toBe(5_000); // unchanged until an explicit recalculation runs

    app(ProfitEngine::class)->recalculate($order->refresh()->load('items.productMirror'));

    expect(OrderProfit::firstWhere('order_id', $order->id)->packaging_cost)->toBe(999_999);
});
