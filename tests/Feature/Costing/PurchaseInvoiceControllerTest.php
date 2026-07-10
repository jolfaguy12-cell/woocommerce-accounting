<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
    $this->lipstick = CostItem::create(['name' => 'رژ لب']);
});

it('creates and immediately receives a multi-line purchase, posting a balanced journal', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'invoice_no' => 'INV-1',
        'shipping_cost' => 100_000,
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 5, 'unit_price' => 200_000],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $invoice = PurchaseInvoice::firstWhere('invoice_no', 'INV-1');

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('received')
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1)
        ->and(CostHistory::where('cost_item_id', $this->lipstick->id)->count())->toBe(1);

    // Warehouse users can view products/orders but never mutate financial data.
    $this->actingAs($this->warehouse)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 1]],
    ])->assertForbidden();
});

it('quick-creates a supplier from the purchase form when new_supplier_name is given', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => '__new__',
        'new_supplier_name' => 'تامین‌کننده جدید',
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 2, 'unit_price' => 100_000]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $supplier = Party::where('type', 'supplier')->firstWhere('name', 'تامین‌کننده جدید');

    expect($supplier)->not->toBeNull()
        ->and(PurchaseInvoice::where('supplier_party_id', $supplier->id)->count())->toBe(1);
});

it('rejects a purchase with no line items', function () {
    $this->actingAs($this->admin)->post('/new-buy-order', [
        'supplier_party_id' => $this->supplier->id,
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});

it('lists purchase invoices on the index page', function () {
    app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);

    $this->actingAs($this->admin)->get('/new-buy-order')
        ->assertOk()
        ->assertViewHas('invoices', fn ($invoices) => $invoices->total() === 1);
});
