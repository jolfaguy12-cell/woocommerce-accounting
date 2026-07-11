<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Services\OrderIngestPipeline;
use Database\Seeders\ChannelSeeder;

beforeEach(function () {
    $this->seed(ChannelSeeder::class);
});

function profileOrder(int $id): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 200000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0,
        'billing' => [
            'first_name' => 'رضا', 'last_name' => 'قاسمی', 'phone' => '09121230000',
            'email' => 'reza@example.com', 'address_1' => 'میدان آزادی', 'address_2' => null,
            'city' => 'تهران', 'state' => '1300', 'postcode' => '1111111111',
        ],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 200000, 'total' => 200000, 'product_id' => 1, 'variation_id' => null]],
    ];
}

it('backfills email and address from the order\'s stored raw payload for parties missing them', function () {
    app(OrderIngestPipeline::class)->ingest(9701, profileOrder(9701), 'manual');
    $party = Party::where('phone', '09121230000')->firstOrFail();

    // Simulate a party created before the email/address columns existed.
    $party->update(['email' => null, 'address' => null]);

    $this->artisan('acc:customers:backfill-profile')->assertSuccessful();

    $party->refresh();
    expect($party->email)->toBe('reza@example.com')
        ->and($party->address)->toContain('میدان آزادی');
});

it('dry-run does not change anything', function () {
    app(OrderIngestPipeline::class)->ingest(9702, profileOrder(9702), 'manual');
    $party = Party::where('phone', '09121230000')->firstOrFail();
    $party->update(['email' => null, 'address' => null]);

    $this->artisan('acc:customers:backfill-profile', ['--dry-run' => true])->assertSuccessful();

    expect($party->fresh()->email)->toBeNull();
});

it('never overwrites an already-present email or address', function () {
    app(OrderIngestPipeline::class)->ingest(9703, profileOrder(9703), 'manual');
    $party = Party::where('phone', '09121230000')->firstOrFail();
    $party->update(['email' => 'kept@example.com']);

    $this->artisan('acc:customers:backfill-profile')->assertSuccessful();

    expect($party->fresh()->email)->toBe('kept@example.com');
});
