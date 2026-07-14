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
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');

    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $item = CostItem::create(['name' => 'اسپری']);
    $this->invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $supplier->id,
        'invoice_date' => Carbon::today()->subDays(6)->toDateString(),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 5, 'unit_price' => 100_000]],
    ]);
});

it('renders the unreceived-goods page for admin, accountant and warehouse, listing the overdue line', function () {
    foreach (['admin', 'accountant', 'warehouse'] as $role) {
        $this->actingAs($this->{$role})
            ->get(route('unreceived-goods.index'))
            ->assertOk()
            ->assertSee('اسپری');
    }
});

it('forbids partner_viewer from the unreceived-goods page', function () {
    $this->actingAs($this->partner)->get(route('unreceived-goods.index'))->assertForbidden();
});

it('shows the overdue tab on the supplier page for admin/accountant only', function () {
    $this->actingAs($this->admin)
        ->get(route('suppliers.overdue', $this->invoice->supplier_party_id))
        ->assertOk()
        ->assertSee('اسپری');

    $this->actingAs($this->warehouse)
        ->get(route('suppliers.overdue', $this->invoice->supplier_party_id))
        ->assertForbidden();
});
