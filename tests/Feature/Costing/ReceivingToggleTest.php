<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Costing\Services\PurchaseReturnService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->partner = User::factory()->create()->assignRole('partner_viewer');
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
});

function toggleInvoice(): PurchaseInvoice
{
    return app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => test()->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => test()->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
}

it('marks an untouched line fully received via toggle-on, writing cost history and a via_toggle receipt', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();

    app(PurchaseInvoiceService::class)->toggleReceived($line, true, $this->admin->id);

    $line->refresh();
    expect($line->received_qty)->toBe(10)
        ->and($line->receiptLines()->count())->toBe(1)
        ->and($line->receiptLines()->first()->via_toggle)->toBeTrue()
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1);

    expect($invoice->refresh()->status)->toBe('received')
        ->and($invoice->journal_entry_id)->not->toBeNull();
});

it('undoes a toggle-on cleanly, resetting qty, deleting the receipt and its cost history', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $service->toggleReceived($line, true, $this->admin->id);
    $service->toggleReceived($line->refresh(), false, $this->admin->id);

    $line->refresh();
    expect($line->received_qty)->toBe(0)
        ->and($line->receiptLines()->count())->toBe(0)
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(0);

    expect($invoice->refresh()->status)->toBe('draft')
        ->and($invoice->journal_entry_id)->toBeNull();
});

it('refuses toggle-on once the line already has any receipt history', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $service->recordReceipt($invoice, [$line->id => ['qty' => 3]], ['received_at' => '2026-07-02'], $this->admin->id);

    $service->toggleReceived($line->refresh(), true, $this->admin->id);
})->throws(InvalidArgumentException::class);

it('refuses toggle-off once a real quantity-flow receipt exists on top of the toggle', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    // Untouched line, partially received via the normal flow — never toggled on, so toggle-off has nothing to undo.
    $service->recordReceipt($invoice, [$line->id => ['qty' => 5]], ['received_at' => '2026-07-02'], $this->admin->id);

    $service->toggleReceived($line->refresh(), false, $this->admin->id);
})->throws(InvalidArgumentException::class);

it('refuses toggle-off once any of the toggled qty has been returned to the supplier', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();
    $service = app(PurchaseInvoiceService::class);

    $service->toggleReceived($line, true, $this->admin->id);

    app(PurchaseReturnService::class)
        ->create($invoice->refresh(), [['line_id' => $line->id, 'qty' => 2]], 'خراب بود', $this->admin->id);

    $service->toggleReceived($line->refresh(), false, $this->admin->id);
})->throws(InvalidArgumentException::class);

it('lets warehouse toggle a line received over HTTP, and blocks partner viewers entirely', function () {
    $invoice = toggleInvoice();
    $line = $invoice->lines->first();

    $this->actingAs($this->warehouse)
        ->post(route('purchases.lines.toggle', [$invoice, $line]), ['received' => '1'])
        ->assertRedirect(route('purchases.show', $invoice));

    expect($line->refresh()->received_qty)->toBe(10);

    $this->actingAs($this->partner)
        ->post(route('purchases.lines.toggle', [$invoice, $line]), ['received' => '0'])
        ->assertForbidden();
});
