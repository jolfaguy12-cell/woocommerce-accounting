<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
});

function searchTestOrder(int $id, array $billing): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 50000, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => $billing,
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ];
}

it('finds an order by order number', function () {
    app(OrderIngestPipeline::class)->ingest(9501, searchTestOrder(9501, [
        'first_name' => 'محمد', 'last_name' => 'صادقی', 'phone' => '09120000001',
    ]), 'manual');

    $this->actingAs($this->admin)->get('/search?q=9501')
        ->assertOk()
        ->assertViewHas('results', fn ($results) => $results->count() === 1
            && $results->first()['type'] === 'order'
            && $results->first()['url'] === route('orders.show', Order::where('hub_order_id', 9501)->first())
        );
});

it('finds a product by name', function () {
    ProductMirror::create(['hub_product_id' => 5001, 'type' => 'simple', 'name' => 'کرم ضدآفتاب', 'payload' => []]);

    $this->actingAs($this->admin)->get('/search?q='.urlencode('ضدآفتاب'))
        ->assertOk()
        ->assertViewHas('results', fn ($results) => $results->count() === 1 && $results->first()['type'] === 'product');
});

it('finds a customer by name and ranks the exact match first', function () {
    Party::createWithRole('customer', ['name' => 'زهرا کریمی', 'phone' => '09120000002']);
    Party::createWithRole('customer', ['name' => 'زهرا کریمی نژاد', 'phone' => '09120000003']);

    $this->actingAs($this->admin)->get('/search?q='.urlencode('زهرا کریمی'))
        ->assertOk()
        ->assertViewHas('results', function ($results) {
            return $results->count() === 2
                && $results->first()['title'] === 'زهرا کریمی'
                && $results->first()['score'] === 3;
        });
});

it('hides customer results from roles without customer access', function () {
    Party::createWithRole('customer', ['name' => 'زهرا کریمی', 'phone' => '09120000002']);

    $this->actingAs($this->warehouse)->get('/search?q='.urlencode('زهرا کریمی'))
        ->assertOk()
        ->assertViewHas('results', fn ($results) => $results->isEmpty());
});

it('returns nothing to partner viewers, who have no search-eligible role', function () {
    ProductMirror::create(['hub_product_id' => 5002, 'type' => 'simple', 'name' => 'کرم ضدآفتاب', 'payload' => []]);

    $this->actingAs($this->partner)->get('/search?q='.urlencode('کرم'))
        ->assertOk()
        ->assertViewHas('results', fn ($results) => $results->isEmpty());
});

it('shows an empty query state without querying anything', function () {
    $this->actingAs($this->admin)->get('/search')
        ->assertOk()
        ->assertViewHas('query', '')
        ->assertViewHas('results', fn ($results) => $results->isEmpty());
});
