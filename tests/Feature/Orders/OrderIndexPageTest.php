<?php

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
});

function indexPageOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 50000, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09121234567'],
        'date_created' => '2026-07-05T10:00:00', 'date_modified' => '2026-07-05T10:00:00',
        'date_paid' => '2026-07-05T10:05:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ], $overrides);
}

it('exposes customer name, payment status and last-sync time to the orders list', function () {
    app(OrderIngestPipeline::class)->ingest(8001, indexPageOrder(8001), 'manual');

    $this->actingAs($this->admin)->get('/orders')->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('orders/index')
            ->has('channels')
            ->has('orders.data', 1)
            ->where('orders.data.0.customer_name', 'زهرا کریمی')
            ->where('orders.data.0.payment_status', 'paid')
            ->has('orders.data.0.updated_at'),
    );
});

it('searches orders by order number and by customer name', function () {
    app(OrderIngestPipeline::class)->ingest(8101, indexPageOrder(8101, [
        'billing' => ['first_name' => 'محمد', 'last_name' => 'صادقی', 'phone' => '09120000001'],
    ]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8102, indexPageOrder(8102, [
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09120000002'],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/orders?search=8101')->assertInertia(
        fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.hub_order_id', 8101),
    );

    $this->actingAs($this->admin)->get('/orders?search='.urlencode('کریمی'))->assertInertia(
        fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.hub_order_id', 8102),
    );
});

it('filters orders by payment status', function () {
    app(OrderIngestPipeline::class)->ingest(8201, indexPageOrder(8201, ['date_paid' => null]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8202, indexPageOrder(8202, ['date_paid' => '2026-07-05T10:05:00']), 'manual');

    $this->actingAs($this->admin)->get('/orders?payment_status=unpaid')->assertInertia(
        fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.hub_order_id', 8201),
    );
});

it('blocks partner viewers from the orders list', function () {
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($partner)->get('/orders')->assertForbidden();
});
