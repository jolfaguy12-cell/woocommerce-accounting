<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
});

it('creates a supplier with profile fields', function () {
    $this->actingAs($this->admin)->post('/suppliers', [
        'name' => 'علی رضایی',
        'shop_name' => 'پخش رضایی',
        'phone' => '09120000000',
        'bank_account_number' => '1234567890',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $supplier = Party::firstWhere('name', 'علی رضایی');

    expect($supplier)->not->toBeNull()
        // A role, not a `type` column — and the shop name and account number are read
        // back from the supplier profile and party_bank_accounts, which is where the
        // form put them.
        ->and($supplier->hasRole('supplier'))->toBeTrue()
        ->and($supplier->shop_name)->toBe('پخش رضایی')
        ->and($supplier->bank_account_number)->toBe('1234567890');

    // Warehouse users can view products/orders but never mutate financial data.
    $this->actingAs($this->warehouse)->post('/suppliers', ['name' => 'x'])->assertForbidden();
});

it('lists suppliers and shows a searchable index', function () {
    Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    Party::createWithRole('customer', ['name' => 'مشتری معمولی']);

    $this->actingAs($this->admin)->get('/suppliers')
        ->assertOk()
        ->assertViewHas('suppliers', fn ($suppliers) => $suppliers->total() === 1);
});

it("shows a supplier's purchase history on its dedicated tab", function () {
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $item = CostItem::create(['name' => 'اسپری']);

    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 5, 'unit_price' => 300_000]],
    ]);
    app(PurchaseInvoiceService::class)->receive($invoice, [$invoice->lines->first()->id => 5]);

    $this->actingAs($this->admin)->get("/suppliers/{$supplier->id}/purchase-history")
        ->assertOk()
        ->assertViewHas('purchases', fn ($purchases) => $purchases->count() === 1
            && $purchases->first()->costItem->id === $item->id);
});

it('shows the supplier accounting dashboard with KPIs and payable balance', function () {
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $item = CostItem::create(['name' => 'اسپری']);

    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $supplier->id,
        'invoice_date' => Carbon::now('Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 5, 'unit_price' => 300_000]],
    ]);
    app(PurchaseInvoiceService::class)->receive($invoice, [$invoice->lines->first()->id => 5]);

    $this->actingAs($this->admin)->get("/suppliers/{$supplier->id}")
        ->assertOk()
        ->assertViewHas('kpis', fn ($kpis) => $kpis['month_value']['value'] === 1_500_000 && $kpis['lifetime_value']['value'] === 1_500_000)
        ->assertViewHas('payableBalance', 1_500_000);
});

it('404s when viewing a non-supplier party as a supplier', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);

    $this->actingAs($this->admin)->get("/suppliers/{$customer->id}")->assertNotFound();
    $this->actingAs($this->admin)->get("/suppliers/{$customer->id}/purchase-history")->assertNotFound();
    $this->actingAs($this->admin)->get("/suppliers/{$customer->id}/transactions")->assertNotFound();
});

it('renders the balance as a plain color-coded signed number, with no unit or debtor/creditor label text', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $debtor = Party::createWithRole('supplier', ['name' => 'تامین‌کننده بدهکار']);
    $invoice = $service->create([
        'supplier_party_id' => $debtor->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 1, 'unit_price' => 500_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);

    $response = $this->actingAs($this->admin)->get('/suppliers');

    $response->assertOk()
        ->assertDontSee('تومان')
        ->assertDontSee('بدهکار ما')
        ->assertDontSee('طلبکار ما');
});
