<?php

use App\Domain\Accounting\Models\Setting;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PackagingCostTier;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Reports\Models\PartnerReport;
use App\Domain\Sync\Models\ReviewItem;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CostCenterSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, ChannelSeeder::class, CostCenterSeeder::class, ExpenseCategorySeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
});

function uiOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 771000,
        'discount_total' => 0, 'shipping_total' => 90000, 'created_via' => 'checkout',
        'date_created' => '2026-07-08T10:00:00', 'date_modified' => '2026-07-08T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'اسپری', 'quantity' => 1, 'subtotal' => 681000, 'total' => 681000, 'product_id' => 5732, 'variation_id' => null]],
    ], $overrides);
}

it('renders staff pages for admins and blocks partner viewers', function () {
    ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    app(OrderIngestPipeline::class)->ingest(5001, uiOrder(5001), 'manual');
    $order = Order::firstWhere('hub_order_id', 5001);

    foreach (['/review', '/orders', "/orders/{$order->id}", '/products', '/fast-forms'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->partner)->get($url)->assertForbidden();
    }

    $this->actingAs($this->partner)->get('/reports')->assertOk();
});

it('maps an unknown source to a new channel from the review center', function () {
    app(OrderIngestPipeline::class)->ingest(5002, uiOrder(5002, ['order_source' => 'gemini', 'created_via' => null]), 'manual');
    $source = ChannelSource::firstWhere('raw_value', 'gemini');

    $this->actingAs($this->admin)->post("/review/sources/{$source->id}/map", [
        'new_channel_name' => 'جمینی',
        'new_channel_cost_model' => 'none',
    ])->assertRedirect();

    expect($source->refresh()->status)->toBe('mapped')
        ->and(Order::firstWhere('hub_order_id', 5002)->refresh()->channel_id)->not->toBeNull()
        ->and(ReviewItem::where('type', 'unknown_source')->where('status', 'open')->count())->toBe(0);
});

it('maps a product to a new cost item and unblocks its orders', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    app(OrderIngestPipeline::class)->ingest(5003, uiOrder(5003), 'manual');

    expect(Order::firstWhere('hub_order_id', 5003)->profit_status)->toBe('blocked_missing_cost');

    $item = CostItem::create(['name' => 'اسپری']);
    $item->costHistory()->create(['unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);

    $this->actingAs($this->admin)->post("/products/{$mirror->id}/map", [
        'cost_item_id' => $item->id, 'multiplier' => 1,
    ])->assertRedirect();

    expect(Order::firstWhere('hub_order_id', 5003)->refresh()->profit_status)->toBe('ok');
});

it('sets a manual real shipping cost and re-evaluates profit', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    $item->costHistory()->create(['unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    app(OrderIngestPipeline::class)->ingest(5004, uiOrder(5004), 'manual');
    $order = Order::firstWhere('hub_order_id', 5004);

    $this->actingAs($this->admin)->post("/orders/{$order->id}/shipping", ['real_cost' => 120_000])->assertRedirect();

    expect($order->refresh()->profit->shipping_real)->toBe(120_000)
        ->and($order->profit->shipping_basis)->toBe('manual')
        ->and($order->profit->version)->toBe(2); // reverse + repost
});

it('sets a manual packaging cost override and re-evaluates profit', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    $item->costHistory()->create(['unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-07-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);

    app(OrderIngestPipeline::class)->ingest(5005, uiOrder(5005), 'manual');
    $order = Order::firstWhere('hub_order_id', 5005);

    $this->actingAs($this->admin)->post("/orders/{$order->id}/packaging", ['real_cost' => 45_000])->assertRedirect();

    expect($order->refresh()->profit->packaging_cost)->toBe(45_000)
        ->and($order->profit->packaging_cost_basis)->toBe('manual');
});

it('manages packaging cost tiers and defaults from the warehouse settings page (admin only)', function () {
    $this->actingAs($this->partner)->get('/warehouse/packaging-cost')->assertForbidden();
    $this->actingAs($this->admin)->get('/warehouse/packaging-cost')->assertOk();

    $this->actingAs($this->admin)->post('/warehouse/packaging-cost/defaults', [
        'default_packaging_cost' => 15_000,
        'default_product_weight_grams' => 200,
        'default_packaging_weight_grams' => 120,
    ])->assertRedirect();

    expect(Setting::get('default_packaging_cost'))->toBe(15_000);

    $this->actingAs($this->admin)->post('/warehouse/packaging-cost/tiers', [
        'min_weight_grams' => 1000, 'cost' => 20_000,
    ])->assertRedirect();

    $tier = PackagingCostTier::firstWhere('min_weight_grams', 1000);
    expect($tier->cost)->toBe(20_000);

    $this->actingAs($this->admin)->put("/warehouse/packaging-cost/tiers/{$tier->id}", [
        'min_weight_grams' => 1000, 'cost' => 25_000,
    ])->assertRedirect();
    expect($tier->refresh()->cost)->toBe(25_000);

    $this->actingAs($this->admin)->delete("/warehouse/packaging-cost/tiers/{$tier->id}")->assertRedirect();
    expect(PackagingCostTier::find($tier->id))->toBeNull();
});

it('records a fast expense from the form', function () {
    $this->actingAs($this->admin)->post('/fast-forms/bank', ['name' => 'بانک ملت'])->assertRedirect();
    $bank = BankAccount::first();

    $this->actingAs($this->admin)->post('/fast-forms/expense', [
        'expense_category_id' => ExpenseCategory::first()->id,
        'bank_account_id' => $bank->id,
        'amount' => 250_000,
        'description' => 'هزینه تست فرم سریع',
    ])->assertRedirect()->assertSessionHas('success');

    expect(Expense::count())->toBe(1);
});

it('finalizes a report from the UI (admin only)', function () {
    $mirror = ProductMirror::create(['hub_product_id' => 5732, 'type' => 'simple', 'name' => 'اسپری', 'payload' => []]);
    $item = CostItem::create(['name' => 'اسپری']);
    $item->costHistory()->create(['unit_cost' => 400_000, 'landed_unit_cost' => 400_000, 'source' => 'manual', 'effective_at' => '2026-06-01']);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);
    app(OrderIngestPipeline::class)->ingest(5005, uiOrder(5005, ['date_created' => '2026-06-10T10:00:00', 'date_modified' => '2026-06-10T10:00:00']), 'manual');

    $this->actingAs($this->admin)->get('/reports/1405-03')->assertOk();
    $this->actingAs($this->partner)->post('/reports/1405-03/finalize')->assertForbidden();
    $this->actingAs($this->admin)->post('/reports/1405-03/finalize', ['acknowledge' => true])->assertRedirect();

    expect(PartnerReport::firstWhere('jalali_period', '1405-03')->state)->toBe('final');
});
