<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');

    $mirror = ProductMirror::create(['hub_product_id' => 9701, 'type' => 'simple', 'name' => 'کالای تست', 'payload' => []]);
    $item = CostItem::create(['name' => 'کالای تست']);
    CostHistory::create([
        'cost_item_id' => $item->id, 'unit_cost' => 100_000, 'landed_unit_cost' => 100_000,
        'source' => 'manual', 'effective_at' => '2026-07-01',
    ]);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);
});

function wholesaleTestOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => ['first_name' => 'سارا', 'last_name' => 'محمدی', 'phone' => '09121234567'],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالای تست', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 9701, 'variation_id' => null]],
    ], $overrides);
}

it('lists only wholesale-labeled customers, excluding regular ones', function () {
    app(OrderIngestPipeline::class)->ingest(9701, wholesaleTestOrder(9701), 'manual');
    app(OrderIngestPipeline::class)->ingest(9702, wholesaleTestOrder(9702, [
        'billing' => ['first_name' => 'رضا', 'last_name' => 'کاظمی', 'phone' => '09129990000'],
    ]), 'manual');

    $wholesaleParty = Party::where('name', 'سارا محمدی')->firstOrFail();
    $regularParty = Party::where('name', 'رضا کاظمی')->firstOrFail();
    $wholesaleParty->update(['is_wholesale' => true]);

    $this->actingAs($this->admin)->get('/wholesale-customers')->assertOk()
        ->assertViewIs('pages.customers.wholesale-index')
        ->assertViewHas('customers', function ($customers) use ($wholesaleParty, $regularParty) {
            return $customers->contains('id', $wholesaleParty->id)
                && ! $customers->contains('id', $regularParty->id);
        });
});

it('searches wholesale customers by name, phone, and telegram id', function () {
    app(OrderIngestPipeline::class)->ingest(9711, wholesaleTestOrder(9711), 'manual');
    $party = Party::where('name', 'سارا محمدی')->firstOrFail();
    $party->update(['is_wholesale' => true, 'telegram_id' => '123456789']);

    $this->actingAs($this->admin)->get('/wholesale-customers?search='.urlencode('محمدی'))->assertViewHas(
        'customers', fn ($customers) => $customers->count() === 1 && $customers->first()->id === $party->id,
    );

    $this->actingAs($this->admin)->get('/wholesale-customers?search=09121234567')->assertViewHas(
        'customers', fn ($customers) => $customers->count() === 1 && $customers->first()->id === $party->id,
    );

    $this->actingAs($this->admin)->get('/wholesale-customers?search=123456789')->assertViewHas(
        'customers', fn ($customers) => $customers->count() === 1 && $customers->first()->id === $party->id,
    );
});

it('saves and edits a customer\'s telegram id', function () {
    app(OrderIngestPipeline::class)->ingest(9721, wholesaleTestOrder(9721), 'manual');
    $party = Party::where('name', 'سارا محمدی')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/customers/{$party->id}/telegram", ['telegram_id' => '987654321'])
        ->assertRedirect();

    expect($party->fresh()->telegram_id)->toBe('987654321');

    $this->actingAs($this->admin)
        ->post("/customers/{$party->id}/telegram", ['telegram_id' => '111222333'])
        ->assertRedirect();

    expect($party->fresh()->telegram_id)->toBe('111222333');

    $this->actingAs($this->admin)
        ->post("/customers/{$party->id}/telegram", ['telegram_id' => ''])
        ->assertRedirect();

    expect($party->fresh()->telegram_id)->toBeNull();
});

it('rejects a telegram id longer than 255 characters', function () {
    app(OrderIngestPipeline::class)->ingest(9731, wholesaleTestOrder(9731), 'manual');
    $party = Party::where('name', 'سارا محمدی')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/customers/{$party->id}/telegram", ['telegram_id' => str_repeat('a', 256)])
        ->assertSessionHasErrors('telegram_id');

    expect($party->fresh()->telegram_id)->toBeNull();
});

it('blocks warehouse staff and partner viewers from the wholesale customers page', function () {
    $warehouse = User::factory()->create()->assignRole('warehouse');
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($warehouse)->get('/wholesale-customers')->assertForbidden();
    $this->actingAs($partner)->get('/wholesale-customers')->assertForbidden();
});
