<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Services\OrderIngestPipeline;
use Database\Seeders\ChannelSeeder;

beforeEach(function () {
    $this->seed(ChannelSeeder::class);
});

function guestOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 200000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0,
        'billing' => [
            'first_name' => 'ملیکا', 'last_name' => 'خلیلی', 'phone' => '',
            'email' => 'melika@example.com',
            'address_1' => 'خیابان ولیعصر پلاک ۱', 'address_2' => null,
            'city' => 'تهران', 'state' => '1300', 'postcode' => '1234567890',
        ],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 200000, 'total' => 200000, 'product_id' => 1, 'variation_id' => null]],
    ], $overrides);
}

it('groups every phone-less guest order under one party by exact name instead of one party per order', function () {
    app(OrderIngestPipeline::class)->ingest(7001, guestOrder(7001), 'manual');
    app(OrderIngestPipeline::class)->ingest(7002, guestOrder(7002), 'manual');
    app(OrderIngestPipeline::class)->ingest(7003, guestOrder(7003), 'manual');

    $parties = Party::where('type', 'customer')->where('name', 'ملیکا خلیلی')->get();

    expect($parties)->toHaveCount(1)
        ->and($parties->first()->orders()->count())->toBe(3);
});

it('keeps different phone-less guests with different names as separate parties', function () {
    app(OrderIngestPipeline::class)->ingest(7101, guestOrder(7101), 'manual');
    app(OrderIngestPipeline::class)->ingest(7102, guestOrder(7102, [
        'billing' => array_merge(guestOrder(7102)['billing'], ['first_name' => 'سارا', 'last_name' => 'رضایی']),
    ]), 'manual');

    expect(Party::where('type', 'customer')->count())->toBe(2);
});

it('captures email and address from billing, never the raw numeric state code', function () {
    app(OrderIngestPipeline::class)->ingest(7301, guestOrder(7301), 'manual');

    $party = Party::where('type', 'customer')->where('name', 'ملیکا خلیلی')->firstOrFail();

    expect($party->email)->toBe('melika@example.com')
        ->and($party->address)->toContain('خیابان ولیعصر پلاک ۱')
        ->and($party->address)->toContain('تهران')
        ->and($party->address)->not->toContain('1300');
});

it('collapses stray whitespace in a guest name so the same customer is not split by formatting', function () {
    app(OrderIngestPipeline::class)->ingest(7401, guestOrder(7401, [
        'billing' => array_merge(guestOrder(7401)['billing'], ['first_name' => '  ملیکا', 'last_name' => 'خلیلی  ']),
    ]), 'manual');
    app(OrderIngestPipeline::class)->ingest(7402, guestOrder(7402), 'manual');

    expect(Party::where('type', 'customer')->count())->toBe(1);
});

it('never grants a role automatically on a phone match alone', function () {
    // A supplier whose phone matches an incoming order. Attaching the customer
    // role here would be an automatic merge on phone alone, which the spec
    // forbids — a shared phone is a hint for review, not proof of identity.
    // Granting a second role stays a deliberate, human action in the UI.
    $supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران', 'phone' => '09121112233']);

    app(OrderIngestPipeline::class)->ingest(7101, guestOrder(7101, [
        'billing' => ['first_name' => 'پخش', 'last_name' => 'تهران', 'phone' => '09121112233', 'email' => null],
    ]), 'manual');

    $supplier->refresh();

    expect($supplier->hasRole('customer'))->toBeFalse()
        ->and($supplier->hasRole('supplier'))->toBeTrue()
        ->and($supplier->orders()->count())->toBe(0)
        ->and(Party::withRole('customer')->where('phone', '09121112233')->count())->toBe(1);
});

it('reuses the same party across roles when the hub itself says it is the same customer', function () {
    // hub_customer_id is a real identifier, not a guess — so a party the hub
    // already knows gains the customer role rather than being duplicated.
    $party = Party::create(['type' => 'other', 'name' => 'شرکت الف', 'hub_customer_id' => 55]);

    app(OrderIngestPipeline::class)->ingest(7301, guestOrder(7301, ['customer_id' => 55]), 'manual');

    $party->refresh();

    expect(Party::where('hub_customer_id', 55)->count())->toBe(1)
        ->and($party->hasRole('customer'))->toBeTrue()
        ->and($party->hasRole('other'))->toBeTrue()
        ->and($party->orders()->count())->toBe(1);
});

it('does not resolve a phone-less guest to a party that has no customer role', function () {
    Party::create(['type' => 'supplier', 'name' => 'ملیکا خلیلی']); // same name, but a supplier

    app(OrderIngestPipeline::class)->ingest(7201, guestOrder(7201), 'manual');

    $customers = Party::withRole('customer')->where('name', 'ملیکا خلیلی')->get();

    expect($customers)->toHaveCount(1)
        ->and($customers->first()->hasRole('supplier'))->toBeFalse();
});
