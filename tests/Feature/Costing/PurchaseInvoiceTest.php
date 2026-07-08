<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostHistory;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->spray = CostItem::create(['name' => 'اسپری رکسونا']);
    $this->lipstick = CostItem::create(['name' => 'رژ لب Von Gee']);
});

it('allocates purchase shipping by quantity by default and computes landed unit cost', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 300_000,
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 20, 'unit_price' => 500_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 10, 'unit_price' => 200_000],
        ],
    ]);

    $spray = $invoice->lines->firstWhere('cost_item_id', $this->spray->id);
    $lipstick = $invoice->lines->firstWhere('cost_item_id', $this->lipstick->id);

    // 300000 / 30 units = 10000 per unit
    expect($spray->shipping_allocated)->toBe(200_000)
        ->and($spray->landed_unit_cost)->toBe(510_000)
        ->and($lipstick->shipping_allocated)->toBe(100_000)
        ->and($lipstick->landed_unit_cost)->toBe(210_000);
});

it('honours manual shipping allocation overrides', function () {
    $invoice = app(PurchaseInvoiceService::class)->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 300_000,
        'shipping_allocation' => 'manual',
        'lines' => [
            ['cost_item_id' => $this->spray->id, 'qty' => 20, 'unit_price' => 500_000, 'shipping_allocated' => 250_000],
            ['cost_item_id' => $this->lipstick->id, 'qty' => 10, 'unit_price' => 200_000, 'shipping_allocated' => 50_000],
        ],
    ]);

    expect($invoice->lines->firstWhere('cost_item_id', $this->spray->id)->landed_unit_cost)->toBe(512_500)
        ->and($invoice->lines->firstWhere('cost_item_id', $this->lipstick->id)->landed_unit_cost)->toBe(205_000);
});

it('writes cost history and posts a balanced journal when the invoice is received', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);

    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    $invoice->refresh();

    expect($invoice->status)->toBe('received')
        ->and(CostHistory::where('cost_item_id', $this->spray->id)->count())->toBe(1)
        ->and(CostHistory::first()->landed_unit_cost)->toBe(480_000)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->journalEntry->lines->sum('debit'))->toBe(4_800_000)
        ->and($invoice->journalEntry->lines->firstWhere('credit', '>', 0)->party_id)->toBe($this->supplier->id);

    // receiving again must not double anything
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    expect(CostHistory::count())->toBe(1);
});

it('supports partial delivery and marks the invoice partial', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'shipping_cost' => 0,
        'lines' => [['cost_item_id' => $this->spray->id, 'qty' => 10, 'unit_price' => 480_000]],
    ]);

    $service->receive($invoice, [$invoice->lines->first()->id => 4]);

    expect($invoice->refresh()->status)->toBe('partial')
        ->and($invoice->lines->first()->received_qty)->toBe(4);
});
