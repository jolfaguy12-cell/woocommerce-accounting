<?php

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed([ChannelSeeder::class, ChartOfAccountsSeeder::class]);

    $mirror = ProductMirror::create(['hub_product_id' => 9501, 'type' => 'simple', 'name' => 'کالا', 'payload' => []]);
    $item = CostItem::create(['name' => 'کالا']);
    CostHistory::create(['cost_item_id' => $item->id, 'unit_cost' => 100000, 'landed_unit_cost' => 100000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    // A second product with no cost mapping: its orders go to the review queue and
    // never post a profit entry, so such a party carries no ledger footprint.
    ProductMirror::create(['hub_product_id' => 9502, 'type' => 'simple', 'name' => 'بدون قیمت', 'payload' => []]);
});

function duplicateGuestOrder(int $id, int $productId = 9501): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0,
        'billing' => ['first_name' => 'یکتای', 'last_name' => 'تکراری‌ساز', 'phone' => ''],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => $productId, 'variation_id' => null]],
    ];
}

/** Simulates the historical bug: before CustomerResolver deduped by name, this order landed on its own party. Relabeling it to the canonical name reproduces the pre-fix duplicate. */
function makeHistoricalDuplicate(int $hubOrderId, string $canonicalName, int $productId = 9501): Order
{
    app(OrderIngestPipeline::class)->ingest($hubOrderId, duplicateGuestOrder($hubOrderId, $productId), 'manual');
    $order = Order::firstWhere('hub_order_id', $hubOrderId);
    $order->customerParty->update(['name' => $canonicalName]);

    return $order->fresh();
}

it('refuses to merge a duplicate that carries financial history, and touches nothing', function () {
    $canonical = Party::create(['type' => 'customer', 'name' => 'علی خلیلی', 'phone' => null]);
    $order = makeHistoricalDuplicate(9602, 'علی خلیلی');

    $duplicatePartyId = $order->customer_party_id;
    $entry = $order->profit->journalEntry;
    $linesBefore = JournalLine::count();

    $this->artisan('acc:customers:merge-duplicates')
        ->expectsOutputToContain('Refused')
        ->assertSuccessful();

    $order->refresh();

    // The order stays put and the ledger is untouched — nothing reversed, nothing
    // reposted. Merging a party with journal history needs the auditable merge
    // flow, which does not exist yet.
    expect($order->customer_party_id)->toBe($duplicatePartyId)
        ->and($order->profit->journal_entry_id)->toBe($entry->id)
        ->and($entry->fresh()->status)->toBe('posted')
        ->and(JournalLine::count())->toBe($linesBefore)
        ->and(Party::whereKey($canonical->id)->exists())->toBeTrue()
        ->and(Party::whereKey($duplicatePartyId)->exists())->toBeTrue();
});

it('names the financial record that blocked the merge', function () {
    Party::create(['type' => 'customer', 'name' => 'علی خلیلی', 'phone' => null]);
    makeHistoricalDuplicate(9605, 'علی خلیلی');

    $this->artisan('acc:customers:merge-duplicates', ['--dry-run' => true])
        ->expectsOutputToContain('posted_order_profit')
        ->assertSuccessful();
});

it('still merges a duplicate with no financial history by reassigning its orders', function () {
    $canonical = Party::create(['type' => 'customer', 'name' => 'رضا بی‌سابقه', 'phone' => null]);

    // Unmapped product → the order is queued for review and posts no journal
    // entry, so this party has no ledger footprint and is safe to merge.
    $order = makeHistoricalDuplicate(9604, 'رضا بی‌سابقه', productId: 9502);
    $duplicatePartyId = $order->customer_party_id;

    expect(JournalLine::where('party_id', $duplicatePartyId)->exists())->toBeFalse();

    $this->artisan('acc:customers:merge-duplicates')->assertSuccessful();

    expect($order->fresh()->customer_party_id)->toBe($canonical->id)
        ->and(Party::whereKey($duplicatePartyId)->exists())->toBeTrue(); // never deleted
});

it('dry-run changes nothing', function () {
    $canonical = Party::create(['type' => 'customer', 'name' => 'رضا بی‌سابقه', 'phone' => null]);
    $order = makeHistoricalDuplicate(9601, 'رضا بی‌سابقه', productId: 9502);
    $originalPartyId = $order->customer_party_id;

    $this->artisan('acc:customers:merge-duplicates', ['--dry-run' => true])->assertSuccessful();

    expect($order->fresh()->customer_party_id)->toBe($originalPartyId)
        ->and(Party::whereKey($canonical->id)->exists())->toBeTrue();
});

it('leaves distinctly-named phone-less parties alone', function () {
    Party::create(['type' => 'customer', 'name' => 'یک نفر دیگر', 'phone' => null]);
    app(OrderIngestPipeline::class)->ingest(9603, array_merge(duplicateGuestOrder(9603), [
        'billing' => ['first_name' => 'دیگری', 'last_name' => 'کاملا متفاوت', 'phone' => ''],
    ]), 'manual');

    $this->artisan('acc:customers:merge-duplicates')->assertSuccessful();

    expect(Party::withRole('customer')->count())->toBe(2);
});
