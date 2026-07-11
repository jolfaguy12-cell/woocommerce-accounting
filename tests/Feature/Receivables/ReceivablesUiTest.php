<?php

use App\Domain\Accounting\Models\Setting;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\ProductCostMapping;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Services\ReceivablesService;
use App\Models\User;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChannelSeeder::class, ChartOfAccountsSeeder::class]);
    Setting::set('receivables_cutover_date', '2026-07-11');
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $mirror = ProductMirror::create(['hub_product_id' => 1, 'type' => 'simple', 'name' => 'کالا', 'payload' => []]);
    $item = CostItem::create(['name' => 'کالا']);
    CostHistory::create([
        'cost_item_id' => $item->id, 'unit_cost' => 100000, 'landed_unit_cost' => 100000,
        'source' => 'manual', 'effective_at' => '2026-07-01',
    ]);
    ProductCostMapping::create(['product_mirror_id' => $mirror->id, 'cost_item_id' => $item->id, 'status' => 'mapped']);
});

function receivablesUiOrder(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'manual',
        'billing' => ['first_name' => 'زهرا', 'last_name' => 'کریمی', 'phone' => '09121234567'],
        'date_created' => '2026-07-12T10:00:00', 'date_modified' => '2026-07-12T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ], $overrides);
}

it('sets a manual payment method on a manual-channel order', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8001, receivablesUiOrder(8001), 'manual');
    expect($order->channel?->slug)->toBe('manual');

    $this->actingAs($this->admin)
        ->post(route('orders.payment-method', $order), ['payment_method_title' => 'کارت به کارت'])
        ->assertRedirect();

    expect($order->fresh()->payment_method_title)->toBe('کارت به کارت');
});

it('refuses to set a payment method on a non-manual order', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8002, receivablesUiOrder(8002, [
        'order_source' => 'basalam',
    ]), 'manual');

    $this->actingAs($this->admin)
        ->post(route('orders.payment-method', $order), ['payment_method_title' => 'کارت به کارت'])
        ->assertNotFound();
});

it('records a settlement from the customer page and shows the updated balance card', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8003, receivablesUiOrder(8003), 'manual');
    $party = $order->customerParty;

    $this->actingAs($this->admin)
        ->post(route('customers.settlement', $party), ['amount' => 500000, 'bank_account_id' => $this->bank->id])
        ->assertRedirect();

    expect(CreditOrder::where('order_id', $order->id)->first()->status)->toBe('settled');

    $this->actingAs($this->admin)->get(route('customers.show', $party))->assertOk()
        ->assertSee('مانده حساب')
        ->assertSee('تسویه');
});

it('records a manual credit sale increasing the customer balance', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8004, receivablesUiOrder(8004), 'manual');
    $party = $order->customerParty;

    $this->actingAs($this->admin)
        ->post(route('customers.credit-sale', $party), ['amount' => 200000, 'description' => 'فروش حضوری'])
        ->assertRedirect();

    expect(app(ReceivablesService::class)->partyOpenBalance($party))->toBe(700000);
});

it('rejects a write-off larger than the open balance', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8005, receivablesUiOrder(8005), 'manual');
    $party = $order->customerParty;

    $this->actingAs($this->admin)
        ->post(route('customers.write-off', $party), ['amount' => 999999999, 'description' => 'نامعتبر'])
        ->assertSessionHasErrors('amount');
});

it('writes off a valid amount', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8006, receivablesUiOrder(8006), 'manual');
    $party = $order->customerParty;

    $this->actingAs($this->admin)
        ->post(route('customers.write-off', $party), ['amount' => 500000, 'description' => 'مشتری غیرقابل دسترس'])
        ->assertRedirect();

    expect(CreditOrder::where('order_id', $order->id)->first()->status)->toBe('settled');
});

it('blocks partner viewers from every receivables action', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8007, receivablesUiOrder(8007), 'manual');
    $party = $order->customerParty;
    $partner = User::factory()->create()->assignRole('partner_viewer');

    $this->actingAs($partner)->post(route('customers.settlement', $party), ['amount' => 1, 'bank_account_id' => $this->bank->id])->assertForbidden();
    $this->actingAs($partner)->post(route('customers.credit-sale', $party), ['amount' => 1, 'description' => 'x'])->assertForbidden();
    $this->actingAs($partner)->post(route('customers.write-off', $party), ['amount' => 1, 'description' => 'x'])->assertForbidden();
    $this->actingAs($partner)->post(route('orders.payment-method', $order), ['payment_method_title' => 'x'])->assertForbidden();
});

it('shows the "ثبت پرداخت" action and payment-method field on a manual order page', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8008, receivablesUiOrder(8008), 'manual');

    $this->actingAs($this->admin)->get(route('orders.show', $order))->assertOk()
        ->assertSee('ثبت پرداخت')
        ->assertSee('شیوه پرداخت ثبت نشده');
});

it('links the customer name on the order page to their profile for admin/accountant but not warehouse', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8010, receivablesUiOrder(8010), 'manual');
    $party = $order->customerParty;
    $warehouse = User::factory()->create()->assignRole('warehouse');
    $link = 'href="'.route('customers.show', $party).'"';

    $this->actingAs($this->admin)->get(route('orders.show', $order))->assertOk()
        ->assertSee($link, false);

    $this->actingAs($warehouse)->get(route('orders.show', $order))->assertOk()
        ->assertDontSee($link, false)
        ->assertSee($party->name);
});

it('flips the order to paid and shows the settlement on both the order and customer pages', function () {
    $order = app(OrderIngestPipeline::class)->ingest(8009, receivablesUiOrder(8009), 'manual');
    $party = $order->customerParty;

    $this->actingAs($this->admin)
        ->post(route('customers.settlement', $party), ['amount' => 500000, 'bank_account_id' => $this->bank->id])
        ->assertRedirect();

    expect($order->fresh()->payment_status)->toBe('paid');

    $this->actingAs($this->admin)->get(route('orders.show', $order))->assertOk()
        ->assertSee('وضعیت تسویه')
        ->assertSee('دریافت وجه');

    $this->actingAs($this->admin)->get(route('customers.show', $party))->assertOk()
        ->assertSee('تاریخچه تسویه‌ها')
        ->assertSee('دریافت وجه');
});
