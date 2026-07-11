<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Receivables\Models\CreditOrder;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

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

it('updates the same customer in place when a phone number is added later via an order edit, instead of creating a duplicate', function () {
    $pipeline = app(OrderIngestPipeline::class);

    $order = $pipeline->ingest(2003, normalizerHubOrder(2003, [
        'billing' => ['first_name' => 'رضا', 'last_name' => 'احمدی', 'phone' => ''],
    ]), 'manual');
    $originalPartyId = $order->customer_party_id;

    expect(Party::where('type', 'customer')->count())->toBe(1)
        ->and(Party::find($originalPartyId)->phone)->toBeNull();

    // Order edited in WooCommerce: a phone number is added, nothing else changes.
    $edited = normalizerHubOrder(2003, [
        'billing' => ['first_name' => 'رضا', 'last_name' => 'احمدی', 'phone' => '09121234567'],
    ]);
    $edited['date_modified'] = '2026-07-08T16:10:00';

    $order = $pipeline->ingest(2003, $edited, 'manual');

    expect(Party::where('type', 'customer')->count())->toBe(1)
        ->and($order->customer_party_id)->toBe($originalPartyId)
        ->and(Party::find($originalPartyId)->phone)->toBe('09121234567');
});

it('resolves different phone formats of the same number to one customer', function () {
    $pipeline = app(OrderIngestPipeline::class);

    $order1 = $pipeline->ingest(2004, normalizerHubOrder(2004, [
        'billing' => ['first_name' => 'سارا', 'last_name' => 'محمدی', 'phone' => '09121234567'],
    ]), 'manual');

    $order2 = $pipeline->ingest(2005, normalizerHubOrder(2005, [
        'billing' => ['first_name' => 'سارا', 'last_name' => 'محمدی', 'phone' => '+989121234567'],
    ]), 'manual');

    expect(Party::where('type', 'customer')->count())->toBe(1)
        ->and($order2->customer_party_id)->toBe($order1->customer_party_id);
});

it('normalizes a Persian-digit phone number instead of stripping it to nothing', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2006, normalizerHubOrder(2006, [
        'billing' => ['first_name' => 'مریم', 'last_name' => 'رضایی', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷'],
    ]), 'manual');

    expect(Party::find($order->customer_party_id)->phone)->toBe('09121234567');
});

it('leaves an unparseable multi-number phone field untouched instead of mangling it', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2007, normalizerHubOrder(2007, [
        'billing' => ['first_name' => 'حسین', 'last_name' => 'کریمی', 'phone' => '09172990309 - 09366225858'],
    ]), 'manual');

    expect(Party::find($order->customer_party_id)->phone)->toBe('09172990309 - 09366225858');
});

it('stores city/province and shipping method from billing when there is no separate shipping address', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2008, normalizerHubOrder(2008, [
        'billing' => ['first_name' => 'الف', 'last_name' => 'ب', 'city' => 'قم', 'state' => 'QHM'],
        'shipping_lines' => [['method_title' => 'پست پیشتاز']],
    ]), 'manual');

    expect($order->city)->toBe('قم')
        ->and($order->province)->toBe('قم')
        ->and($order->shipping_method_title)->toBe('پست پیشتاز');
});

it('prefers the shipping address over billing when both are present', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2009, normalizerHubOrder(2009, [
        'billing' => ['first_name' => 'الف', 'last_name' => 'ب', 'city' => 'قم', 'state' => 'QHM'],
        'shipping' => ['city' => 'تهران', 'state' => 'THR'],
    ]), 'manual');

    expect($order->city)->toBe('تهران')
        ->and($order->province)->toBe('تهران');
});

it('does not guess a province from an unrecognized channel-specific state code, but still keeps the city', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2010, normalizerHubOrder(2010, [
        'billing' => ['first_name' => 'الف', 'last_name' => 'ب', 'city' => 'تهران', 'state' => '607'],
    ]), 'manual');

    expect($order->city)->toBe('تهران')
        ->and($order->province)->toBeNull();
});

it('defaults to Qom when no address was given at all', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2011, normalizerHubOrder(2011), 'manual');

    expect($order->city)->toBe('قم')
        ->and($order->province)->toBe('قم');
});

it('defaults to Qom when billing has no city even if a state code is present', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2012, normalizerHubOrder(2012, [
        'billing' => ['first_name' => 'الف', 'last_name' => 'ب', 'state' => 'THR'],
    ]), 'manual');

    expect($order->city)->toBe('قم')
        ->and($order->province)->toBe('قم');
});

it('keeps a manually-set payment method through a resync whose payload has none', function () {
    $pipeline = app(OrderIngestPipeline::class);
    $order = $pipeline->ingest(2013, normalizerHubOrder(2013), 'manual');
    $order->update(['payment_method_title' => 'کارت به کارت (دستی)']);

    $edited = normalizerHubOrder(2013);
    $edited['date_modified'] = '2026-07-08T16:10:00';
    $order = $pipeline->ingest(2013, $edited, 'manual');

    expect($order->payment_method_title)->toBe('کارت به کارت (دستی)');
});

it('preserves payment_status=paid once a linked credit order is settled, even with no date_paid in the payload', function () {
    $pipeline = app(OrderIngestPipeline::class);
    $order = $pipeline->ingest(2014, normalizerHubOrder(2014, [
        'billing' => ['first_name' => 'رضا', 'last_name' => 'احمدی', 'phone' => '09121110000'],
    ]), 'manual');

    expect($order->payment_status)->toBe('unpaid');

    CreditOrder::create([
        'uuid' => (string) Str::uuid(),
        'order_id' => $order->id,
        'party_id' => $order->customer_party_id,
        'total_due' => 200000,
        'paid_total' => 200000,
        'status' => 'settled',
    ]);

    $edited = normalizerHubOrder(2014, [
        'billing' => ['first_name' => 'رضا', 'last_name' => 'احمدی', 'phone' => '09121110000'],
    ]);
    $edited['date_modified'] = '2026-07-08T16:10:00';
    $order = $pipeline->ingest(2014, $edited, 'manual');

    expect($order->payment_status)->toBe('paid');
});

it('never treats a manual-channel order as paid, even when the hub payload carries a date_paid', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2015, normalizerHubOrder(2015, [
        'created_via' => 'admin',
        'date_paid' => '2026-07-08T16:05:00',
    ]), 'manual');

    // WooCommerce stamps date_paid the moment a manually-created order is
    // saved as processing/completed, regardless of whether money actually
    // changed hands — 'paid' can only ever come from our own settlement panel.
    expect($order->payment_status)->toBe('unpaid')
        ->and($order->date_paid)->toBeNull();
});

it('treats a real gateway transaction id as paid even with no date_paid in the payload', function () {
    $order = app(OrderIngestPipeline::class)->ingest(2016, normalizerHubOrder(2016, [
        'order_source' => 'torob',
        'payment_method' => 'WC_Zibal',
        'payment_method_title' => 'زیبال',
        'transaction_id' => '4675288251',
    ]), 'manual');

    // Some gateway-integration plugins move the order straight to processing
    // without ever calling WooCommerce's payment_complete(), so date_paid
    // never gets stamped even though Zibal already confirmed the payment.
    expect($order->payment_status)->toBe('paid')
        ->and($order->gateway_transaction_id)->toBe('4675288251');
});
