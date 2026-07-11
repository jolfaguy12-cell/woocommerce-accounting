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
