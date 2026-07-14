<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Models\PurchaseInvoiceReceipt;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری']);
});

it('records a partial receipt with date/package/notes without posting the journal until fully received', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();

    $receipt = $service->recordReceipt($invoice, [
        $line->id => ['qty' => 4, 'package_count' => 2, 'package_label' => 'کارتن'],
    ], ['received_at' => '2026-07-02', 'notes' => 'محموله اول'], $this->admin->id);

    expect($receipt)->toBeInstanceOf(PurchaseInvoiceReceipt::class)
        ->and($receipt->received_at->toDateString())->toBe('2026-07-02')
        ->and($receipt->notes)->toBe('محموله اول')
        ->and($receipt->created_by)->toBe($this->admin->id)
        ->and($receipt->lines->first()->qty)->toBe(4)
        ->and($receipt->lines->first()->package_count)->toBe(2)
        ->and($receipt->lines->first()->package_label)->toBe('کارتن');

    $invoice->refresh();
    expect($invoice->lines->first()->received_qty)->toBe(4)
        ->and($invoice->status)->toBe('partial')
        ->and($invoice->journal_entry_id)->toBeNull()
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1);
});

it('accumulates multiple partial receipts and posts the journal exactly once fully received', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();

    $service->recordReceipt($invoice, [$line->id => ['qty' => 4]], ['received_at' => '2026-07-02'], $this->admin->id);
    $service->recordReceipt($invoice, [$line->id => ['qty' => 6]], ['received_at' => '2026-07-05'], $this->admin->id);

    $invoice->refresh();
    expect($invoice->lines->first()->received_qty)->toBe(10)
        ->and($invoice->status)->toBe('received')
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->receipts)->toHaveCount(2)
        // first-received-crossing cost history is written only once, on the first event.
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1);
});

it('caps a receipt qty at what remains outstanding on the line', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();

    $service->recordReceipt($invoice, [$line->id => ['qty' => 999]], ['received_at' => '2026-07-02'], $this->admin->id);

    expect($invoice->refresh()->lines->first()->received_qty)->toBe(10);
});

it('throws when no line in the receipt has a positive qty', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();

    $service->recordReceipt($invoice, [$line->id => ['qty' => 0]], ['received_at' => '2026-07-02'], $this->admin->id);
})->throws(InvalidArgumentException::class);

it('lets a warehouse user record a receipt over HTTP but blocks everything else in Purchasing', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => now(),
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $line = $invoice->lines->first();

    $this->actingAs($this->warehouse)->get("/new-buy-order/{$invoice->id}")->assertOk();

    $this->actingAs($this->warehouse)->post("/new-buy-order/{$invoice->id}/receipts", [
        'received_at' => now()->toDateString(),
        'lines' => [$line->id => ['qty' => 3]],
    ])->assertRedirect(route('purchases.show', $invoice));

    expect($invoice->refresh()->lines->first()->received_qty)->toBe(3);

    $this->actingAs($this->warehouse)->get('/new-buy-order/create')->assertForbidden();
    $this->actingAs($this->warehouse)->put("/new-buy-order/{$invoice->id}", [])->assertForbidden();
    $this->actingAs($this->warehouse)->post("/new-buy-order/{$invoice->id}/finalize")->assertForbidden();
    $this->actingAs($this->warehouse)->post("/new-buy-order/{$invoice->id}/returns", ['reason' => 'x', 'lines' => []])->assertForbidden();
});
