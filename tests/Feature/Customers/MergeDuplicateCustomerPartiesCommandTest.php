<?php

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
});

function duplicateGuestOrder(int $id): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0,
        'billing' => ['first_name' => 'یکتای', 'last_name' => 'تکراری‌ساز', 'phone' => ''],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 9501, 'variation_id' => null]],
    ];
}

/** Simulates the historical bug: before CustomerResolver deduped by name, this order landed on its own party. Relabeling it to the canonical name reproduces the pre-fix duplicate for the test. */
function makeHistoricalDuplicate(int $hubOrderId, string $canonicalName): Order
{
    app(OrderIngestPipeline::class)->ingest($hubOrderId, duplicateGuestOrder($hubOrderId), 'manual');
    $order = Order::firstWhere('hub_order_id', $hubOrderId);
    $order->customerParty->update(['name' => $canonicalName]);

    return $order->fresh();
}

it('dry-run reports duplicates without changing anything', function () {
    $canonical = Party::create(['type' => 'customer', 'name' => 'علی خلیلی', 'phone' => null]);
    $order = makeHistoricalDuplicate(9601, 'علی خلیلی');
    $originalPartyId = $order->customer_party_id;
    $originalEntryId = $order->profit->journal_entry_id;

    $this->artisan('acc:customers:merge-duplicates', ['--dry-run' => true])->assertSuccessful();

    expect($order->fresh()->customer_party_id)->toBe($originalPartyId)
        ->and($order->fresh()->profit->journal_entry_id)->toBe($originalEntryId)
        ->and(Party::whereKey($canonical->id)->exists())->toBeTrue();
});

it('merges phone-less duplicate parties by name: reassigns orders and reposts the AR journal line under the canonical party, without deleting the duplicate', function () {
    $canonical = Party::create(['type' => 'customer', 'name' => 'علی خلیلی', 'phone' => null]);
    $order = makeHistoricalDuplicate(9602, 'علی خلیلی');
    $duplicatePartyId = $order->customer_party_id;
    $oldEntry = $order->profit->journalEntry;

    $this->artisan('acc:customers:merge-duplicates')->assertSuccessful();

    $order->refresh();
    expect($order->customer_party_id)->toBe($canonical->id)
        ->and(Party::whereKey($duplicatePartyId)->exists())->toBeTrue() // never deleted
        ->and($oldEntry->fresh()->status)->toBe('reversed');

    $newEntry = $order->profit->fresh()->journalEntry;
    expect($newEntry->id)->not->toBe($oldEntry->id)
        ->and($newEntry->status)->toBe('posted')
        ->and($newEntry->lines->firstWhere('account_id', $oldEntry->lines->first()->account_id)->party_id)->toBe($canonical->id);
});

it('leaves distinctly-named phone-less parties alone', function () {
    Party::create(['type' => 'customer', 'name' => 'یک نفر دیگر', 'phone' => null]);
    app(OrderIngestPipeline::class)->ingest(9603, array_merge(duplicateGuestOrder(9603), [
        'billing' => ['first_name' => 'دیگری', 'last_name' => 'کاملا متفاوت', 'phone' => ''],
    ]), 'manual');

    $this->artisan('acc:customers:merge-duplicates')->assertSuccessful();

    expect(Party::where('type', 'customer')->count())->toBe(2);
});
