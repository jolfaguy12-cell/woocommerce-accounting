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

    $mirror = ProductMirror::create(['hub_product_id' => 9001, 'type' => 'simple', 'name' => 'کالای تست', 'payload' => []]);
    $item = CostItem::create(['name' => 'کالای تست']);
    CostHistory::create([
        'cost_item_id' => $item->id, 'unit_cost' => 100_000, 'landed_unit_cost' => 100_000,
        'source' => 'manual', 'effective_at' => '2026-07-01',
    ]);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);
});

function customerOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => ['first_name' => 'نگار', 'last_name' => 'رستمی', 'phone' => '09121110000'],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالای تست', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 9001, 'variation_id' => null]],
    ], $overrides);
}

it('aggregates a customer\'s purchase counts, valid-order volume, and last purchase on the list page', function () {
    app(OrderIngestPipeline::class)->ingest(9101, customerOrder(9101, ['total' => 500000]), 'manual');
    app(OrderIngestPipeline::class)->ingest(9102, customerOrder(9102, ['total' => 300000, 'status' => 'pending', 'date_paid' => null]), 'manual');
    app(OrderIngestPipeline::class)->ingest(9103, customerOrder(9103, ['total' => 200000, 'status' => 'cancelled', 'date_paid' => null]), 'manual');

    $this->actingAs($this->admin)->get('/customers')->assertOk()
        ->assertViewIs('pages.customers.index')
        ->assertViewHas('customers', function ($customers) {
            $c = $customers->firstWhere('name', 'نگار رستمی');

            return $c && (int) $c->orders_count === 3
                && (int) $c->paid_count === 1
                && (int) $c->pending_count === 1
                && (int) $c->void_count === 1
                && (int) $c->total_volume === 500000; // only the valid order counts toward volume
        });
});

it('searches customers by name and by phone', function () {
    app(OrderIngestPipeline::class)->ingest(9201, customerOrder(9201), 'manual');
    app(OrderIngestPipeline::class)->ingest(9202, customerOrder(9202, [
        'billing' => ['first_name' => 'محمد', 'last_name' => 'یوسفی', 'phone' => '09129998888'],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/customers?search='.urlencode('رستمی'))->assertViewHas(
        'customers', fn ($customers) => $customers->count() === 1 && $customers->first()->name === 'نگار رستمی',
    );

    $this->actingAs($this->admin)->get('/customers?search=09129998888')->assertViewHas(
        'customers', fn ($customers) => $customers->count() === 1 && $customers->first()->name === 'محمد یوسفی',
    );
});

it('excludes orders with unresolved profit from the summed profit total, and reports how many are excluded', function () {
    app(OrderIngestPipeline::class)->ingest(9401, customerOrder(9401), 'manual');
    app(OrderIngestPipeline::class)->ingest(9402, customerOrder(9402, [
        'line_items' => [['id' => 94020, 'name' => 'کالای بدون بها', 'quantity' => 1, 'subtotal' => 400000, 'total' => 400000, 'product_id' => 8888, 'variation_id' => null]],
    ]), 'manual');

    $party = Party::where('name', 'نگار رستمی')->firstOrFail();
    $resolvedOrder = $party->orders()->where('hub_order_id', 9401)->firstOrFail();
    $expectedProfit = $resolvedOrder->profit->operational_profit;

    $this->actingAs($this->admin)->get("/customers/{$party->id}")->assertOk()
        ->assertViewHas('summary', function ($summary) use ($expectedProfit) {
            return $summary['unresolved_profit_count'] === 1
                && $summary['profit'] === $expectedProfit;
        });
});

it('toggles the wholesale label with an audit trail', function () {
    app(OrderIngestPipeline::class)->ingest(9501, customerOrder(9501), 'manual');
    $party = Party::where('name', 'نگار رستمی')->firstOrFail();

    $this->actingAs($this->admin)
        ->post("/customers/{$party->id}/wholesale", ['is_wholesale' => '1'])
        ->assertRedirect();

    $party->refresh();
    expect($party->is_wholesale)->toBeTrue()
        ->and($party->wholesale_labeled_at)->not->toBeNull()
        ->and($party->wholesale_labeled_by)->toBe($this->admin->id);
});

it('blocks warehouse staff and partner viewers from customer management', function () {
    $warehouse = User::factory()->create()->assignRole('warehouse');
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($warehouse)->get('/customers')->assertForbidden();
    $this->actingAs($partner)->get('/customers')->assertForbidden();
});
