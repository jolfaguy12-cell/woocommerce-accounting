<?php

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Products\Services\InventorySnapshotService;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChannelSeeder::class);
});

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the TailAdmin dashboard for every role', function () {
    foreach (['admin', 'warehouse', 'partner_viewer'] as $role) {
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertViewIs('pages.dashboard.ecommerce')
            ->assertSee('dir="rtl"', false);
    }
});

it('hides sales/customer-growth figures from warehouse users but keeps stock and recent orders visible', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $warehouse = User::factory()->create()->assignRole('warehouse');

    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertViewHas('canSeeFinancials', true)
        ->assertViewHas('kpis', fn ($kpis) => $kpis['new_customers'] !== null && $kpis['gross_sales'] !== null);

    $this->actingAs($warehouse)->get('/dashboard')->assertOk()
        ->assertViewHas('canSeeFinancials', false)
        ->assertViewHas('kpis', fn ($kpis) => $kpis['new_customers'] === null
            && $kpis['gross_sales'] === null
            && $kpis['stock_count'] !== null);
});

it('shows the latest inventory snapshot on the dashboard, or "no data yet" before one has ever run', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertViewHas('kpis', fn ($kpis) => $kpis['inventory_units'] === null && $kpis['inventory_value'] === null);

    ProductMirror::create(['hub_product_id' => 51, 'type' => 'simple', 'name' => 'کالا', 'price' => 10_000, 'stock_quantity' => 4, 'payload' => []]);
    app(InventorySnapshotService::class)->refresh();

    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertViewHas('kpis', fn ($kpis) => $kpis['inventory_units'] === 4 && $kpis['inventory_value'] === 40_000);
});

it('shows the 10 most recent orders on the dashboard, linking each to its real order page', function () {
    app(OrderIngestPipeline::class)->ingest(6601, [
        'id' => 6601, 'status' => 'completed', 'currency' => 'IRT', 'total' => 150000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'customer_id' => 0, 'billing' => ['first_name' => 'کاربر', 'last_name' => 'تست', 'phone' => '09120001111'],
        'date_created' => now()->toIso8601String(), 'date_modified' => now()->toIso8601String(),
        'date_paid' => now()->toIso8601String(), 'meta' => [],
        'line_items' => [['id' => 66010, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 150000, 'total' => 150000, 'product_id' => 1, 'variation_id' => null]],
    ], 'manual');
    $order = Order::firstWhere('hub_order_id', 6601);

    $admin = User::factory()->create()->assignRole('admin');

    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertViewHas('recentOrders', fn ($orders) => count($orders) > 0 && $orders[0]['id'] === $order->id)
        ->assertSee(route('orders.show', $order), false);
});

it('serves the TailAdmin demo pages behind auth', function () {
    $paths = ['/calendar', '/form-elements', '/basic-tables', '/blank'];

    foreach ($paths as $path) {
        $this->get($path)->assertRedirect('/login');
    }

    $this->actingAs(User::factory()->create()->assignRole('admin'));
    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});
