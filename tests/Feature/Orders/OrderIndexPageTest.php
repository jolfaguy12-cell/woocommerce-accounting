<?php

use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\RoleSeeder;

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

    $this->actingAs($this->admin)->get('/orders')->assertOk()
        ->assertViewIs('pages.orders.index')
        ->assertViewHas('channels')
        ->assertViewHas('orders', function ($orders) {
            return $orders->count() === 1
                && $orders->items()[0]->customerParty->name === 'زهرا کریمی'
                && $orders->items()[0]->payment_status === 'paid'
                && $orders->items()[0]->updated_at !== null;
        });
});

it('searches orders by order number and by customer name', function () {
    app(OrderIngestPipeline::class)->ingest(8101, indexPageOrder(8101, [
        'billing' => ['first_name' => 'محمد', 'last_name' => 'صادقی', 'phone' => '09120000001'],
    ]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8102, indexPageOrder(8102, [
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09120000002'],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/orders?search=8101')->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8101,
    );

    $this->actingAs($this->admin)->get('/orders?search='.urlencode('کریمی'))->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8102,
    );
});

it('filters orders by payment status', function () {
    app(OrderIngestPipeline::class)->ingest(8201, indexPageOrder(8201, ['date_paid' => null]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8202, indexPageOrder(8202, ['date_paid' => '2026-07-05T10:05:00']), 'manual');

    $this->actingAs($this->admin)->get('/orders?payment_status=unpaid')->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8201,
    );
});

it('exposes every real order status dynamically, not a hard-coded list', function () {
    app(OrderIngestPipeline::class)->ingest(8301, indexPageOrder(8301, ['status' => 'completed']), 'manual');
    app(OrderIngestPipeline::class)->ingest(8302, indexPageOrder(8302, [
        'status' => 'bslm-shipping', 'order_source' => 'basalam', 'meta' => [],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/orders')->assertViewHas('statuses', function ($statuses) {
        return $statuses->count() === 2
            && in_array($statuses->first()->status, ['completed', 'bslm-shipping'], true);
    });
});

it('filters orders by status', function () {
    app(OrderIngestPipeline::class)->ingest(8401, indexPageOrder(8401, ['status' => 'completed']), 'manual');
    app(OrderIngestPipeline::class)->ingest(8402, indexPageOrder(8402, ['status' => 'processing']), 'manual');

    $this->actingAs($this->admin)->get('/orders?status=processing')->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8402,
    );
});

it('filters orders with no resolved channel via the unmapped sentinel', function () {
    app(OrderIngestPipeline::class)->ingest(8501, indexPageOrder(8501, ['order_source' => 'a-brand-new-source', 'meta' => []]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8502, indexPageOrder(8502), 'manual'); // resolves to website

    $this->actingAs($this->admin)->get('/orders?channel_id=unmapped')->assertOk()
        ->assertViewHas('orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8501)
        ->assertViewHas('unmappedCount', 1);
});

it('filters orders by a jalali-picked date range', function () {
    app(OrderIngestPipeline::class)->ingest(8601, indexPageOrder(8601, ['date_created' => '2026-07-01T10:00:00']), 'manual');
    app(OrderIngestPipeline::class)->ingest(8602, indexPageOrder(8602, ['date_created' => '2026-07-08T10:00:00']), 'manual');

    $this->actingAs($this->admin)->get('/orders?date_from=2026-07-05&date_to=2026-07-10')->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 8602,
    );
});

it('blocks partner viewers from the orders list', function () {
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($partner)->get('/orders')->assertForbidden();
});

it('sorts orders by total ascending and descending', function () {
    app(OrderIngestPipeline::class)->ingest(8701, indexPageOrder(8701, ['total' => 300000]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8702, indexPageOrder(8702, ['total' => 100000]), 'manual');
    app(OrderIngestPipeline::class)->ingest(8703, indexPageOrder(8703, ['total' => 200000]), 'manual');

    $this->actingAs($this->admin)->get('/orders?sort=total')->assertViewHas(
        'orders', fn ($orders) => $orders->pluck('hub_order_id')->all() === [8702, 8703, 8701],
    );

    // A leading '-' is descending — the whole sort contract, in one character.
    $this->actingAs($this->admin)->get('/orders?sort=-total')->assertViewHas(
        'orders', fn ($orders) => $orders->pluck('hub_order_id')->all() === [8701, 8703, 8702],
    );
});

it('sorts orders by several columns at once, in the order given', function () {
    // Same total, different dates: the second sort key is what breaks the tie.
    app(OrderIngestPipeline::class)->ingest(8801, indexPageOrder(8801, ['total' => 100000, 'date_created' => '2026-01-03T10:00:00']), 'manual');
    app(OrderIngestPipeline::class)->ingest(8802, indexPageOrder(8802, ['total' => 100000, 'date_created' => '2026-01-01T10:00:00']), 'manual');
    app(OrderIngestPipeline::class)->ingest(8803, indexPageOrder(8803, ['total' => 500000, 'date_created' => '2026-01-02T10:00:00']), 'manual');

    $this->actingAs($this->admin)->get('/orders?sort=total,order_date')->assertViewHas(
        'orders', fn ($orders) => $orders->pluck('hub_order_id')->all() === [8802, 8801, 8803],
    );
});

it('never drops an active filter when the sort changes', function () {
    // The bug that motivated TableQuery: hand-built sort links forgot the filters.
    $this->actingAs($this->admin)->get('/orders?status=processing&sort=-total')
        ->assertViewHas('query', function ($query) {
            $url = $query->sortUrl('order_date');

            return str_contains($url, 'status=processing') && str_contains($url, 'sort=order_date');
        });
});

it('falls back to the default sort for an unrecognized sort key', function () {
    app(OrderIngestPipeline::class)->ingest(8801, indexPageOrder(8801), 'manual');

    $this->actingAs($this->admin)->get('/orders?sort=some_unknown_column')->assertOk();
});

it('sorts orders by profit via the order_profits join without erroring', function () {
    app(OrderIngestPipeline::class)->ingest(8901, indexPageOrder(8901), 'manual');

    $this->actingAs($this->admin)->get('/orders?sort=operational_profit&dir=asc')->assertOk();
});

it('searches orders by city', function () {
    app(OrderIngestPipeline::class)->ingest(9001, indexPageOrder(9001, [
        'billing' => ['first_name' => 'محمد', 'last_name' => 'صادقی', 'phone' => '09120000001', 'city' => 'قم', 'state' => 'QHM'],
    ]), 'manual');
    app(OrderIngestPipeline::class)->ingest(9002, indexPageOrder(9002, [
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09120000002', 'city' => 'تهران', 'state' => 'THR'],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/orders?search='.urlencode('قم'))->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 9001,
    );
});

it('filters orders by province', function () {
    app(OrderIngestPipeline::class)->ingest(9101, indexPageOrder(9101, [
        'billing' => ['first_name' => 'محمد', 'last_name' => 'صادقی', 'phone' => '09120000003', 'city' => 'قم', 'state' => 'QHM'],
    ]), 'manual');
    app(OrderIngestPipeline::class)->ingest(9102, indexPageOrder(9102, [
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09120000004', 'city' => 'تهران', 'state' => 'THR'],
    ]), 'manual');

    $this->actingAs($this->admin)->get('/orders?province='.urlencode('قم'))->assertViewHas(
        'orders', fn ($orders) => $orders->count() === 1 && $orders->items()[0]->hub_order_id === 9101,
    )->assertViewHas('provinces', fn ($provinces) => $provinces->sort()->values()->all() === ['تهران', 'قم']);
});
