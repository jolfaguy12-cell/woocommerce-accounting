<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Services\PayablesService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
});

it('updates a supplier profile from the shared edit form', function () {
    $this->actingAs($this->admin)->put("/suppliers/{$this->supplier->id}", [
        'name' => 'پخش تهران (اصلاح‌شده)',
        'shop_name' => 'فروشگاه جدید',
        'email' => 'supplier@example.com',
        'address' => 'تهران',
        'notes' => 'یادداشت تست',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->supplier->refresh();
    expect($this->supplier->name)->toBe('پخش تهران (اصلاح‌شده)')
        ->and($this->supplier->shop_name)->toBe('فروشگاه جدید')
        ->and($this->supplier->email)->toBe('supplier@example.com')
        ->and($this->supplier->notes)->toBe('یادداشت تست');

    $this->actingAs($this->warehouse)->put("/suppliers/{$this->supplier->id}", ['name' => 'x'])
        ->assertForbidden();
});

it('records a bank payment to a supplier, posting a balanced journal and reducing the payable balance', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);

    $this->actingAs($this->admin)->post("/suppliers/{$this->supplier->id}/pay", [
        'amount' => 2_000_000,
        'bank_account_id' => $this->bank->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(3_000_000);

    $this->actingAs($this->warehouse)->post("/suppliers/{$this->supplier->id}/pay", [
        'amount' => 1,
        'bank_account_id' => $this->bank->id,
    ])->assertForbidden();
});

it('sorts and searches the suppliers list by payable balance and name', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $other = Party::createWithRole('supplier', ['name' => 'آریا پخش']);

    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 1, 'unit_price' => 1_000_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);

    $this->actingAs($this->admin)->get('/suppliers?sort=-payable_balance')
        ->assertOk()
        ->assertViewHas('suppliers', fn ($suppliers) => $suppliers->first()->id === $this->supplier->id);

    $this->actingAs($this->admin)->get('/suppliers?search='.urlencode('آریا'))
        ->assertOk()
        ->assertViewHas('suppliers', fn ($suppliers) => $suppliers->total() === 1 && $suppliers->first()->id === $other->id);
});

it('paginates and sorts the supplier transactions tab', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 1, 'unit_price' => 500_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);

    $this->actingAs($this->admin)->get("/suppliers/{$this->supplier->id}/transactions")
        ->assertOk()
        ->assertViewHas('transactions', fn ($t) => $t->total() === 1)
        ->assertViewHas('balance', 500_000);
});
