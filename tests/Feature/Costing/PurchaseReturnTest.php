<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Costing\Services\PurchaseReturnService;
use App\Domain\Receivables\Services\PayablesService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
});

it('returns goods to the supplier, reducing AP and inventory via its own journal entry, leaving the original invoice journal untouched', function () {
    $invoiceService = app(PurchaseInvoiceService::class);
    $invoice = $invoiceService->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    $invoiceService->receive($invoice, [$line->id => 10]);
    $originalEntryId = $invoice->refresh()->journal_entry_id;
    $inventoryBefore = Account::firstWhere('code', '1300')->balance();

    $return = app(PurchaseReturnService::class)->create($invoice, [['line_id' => $line->id, 'qty' => 3]], 'کالای معیوب', $this->admin->id);

    expect($return->lines)->toHaveCount(1)
        ->and($return->lines->first()->qty)->toBe(3)
        ->and($return->lines->first()->unit_cost)->toBe($line->landed_unit_cost)
        ->and($invoice->refresh()->journal_entry_id)->toBe($originalEntryId) // untouched
        ->and($invoice->lines->first()->returned_qty)->toBe(3)
        ->and($invoice->lines->first()->returnableQty())->toBe(7);

    $returnTotal = 3 * $line->landed_unit_cost;
    expect(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(5_000_000 - $returnTotal)
        ->and(Account::firstWhere('code', '1300')->balance())->toBe($inventoryBefore - $returnTotal);
});

it('rejects returning more than what remains returnable on a line', function () {
    $invoiceService = app(PurchaseInvoiceService::class);
    $invoice = $invoiceService->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    $invoiceService->receive($invoice, [$line->id => 4]);

    app(PurchaseReturnService::class)->create($invoice, [['line_id' => $line->id, 'qty' => 5]], 'بیش از حد', $this->admin->id);
})->throws(InvalidArgumentException::class);

it('rejects a return with no positive-qty line', function () {
    $invoiceService = app(PurchaseInvoiceService::class);
    $invoice = $invoiceService->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    $invoiceService->receive($invoice, [$line->id => 10]);

    app(PurchaseReturnService::class)->create($invoice, [['line_id' => $line->id, 'qty' => 0]], 'خالی', $this->admin->id);
})->throws(InvalidArgumentException::class);

it('records a return over HTTP for admin but forbids warehouse', function () {
    $invoiceService = app(PurchaseInvoiceService::class);
    $invoice = $invoiceService->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();
    $invoiceService->receive($invoice, [$line->id => 10]);

    $this->actingAs($this->warehouse)->post("/new-buy-order/{$invoice->id}/returns", [
        'reason' => 'test',
        'lines' => [$line->id => ['qty' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->admin)->post("/new-buy-order/{$invoice->id}/returns", [
        'reason' => 'کالای معیوب',
        'lines' => [$line->id => ['qty' => 2]],
    ])->assertRedirect(route('purchases.show', $invoice));

    expect($invoice->refresh()->lines->first()->returned_qty)->toBe(2);
});
