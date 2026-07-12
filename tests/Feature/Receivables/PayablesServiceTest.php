<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Services\PayablesService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Support\Design\TableQuery;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->item = CostItem::create(['name' => 'اسپری']);
});

it('reports a positive payable balance after a received invoice, reduced by a payment', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->item->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);

    $payables = app(PayablesService::class);
    expect($payables->partyPayableBalance($this->supplier))->toBe(5_000_000);

    app(PaymentRecorder::class)->pay($this->supplier, 2_000_000, $this->bank->id);

    expect($payables->partyPayableBalance($this->supplier))->toBe(3_000_000);
});

it('goes negative (we are owed) when paid more than currently owed', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->item->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);

    app(PaymentRecorder::class)->pay($this->supplier, 150_000, $this->bank->id);

    expect(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(-50_000);
});

it('builds a running-balance ledger for a supplier, newest first', function () {
    $service = app(PurchaseInvoiceService::class);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $this->item->id, 'qty' => 10, 'unit_price' => 500_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 10]);
    app(PaymentRecorder::class)->pay($this->supplier, 2_000_000, $this->bank->id);

    $query = new TableQuery(request: Request::create('/'), sortable: ['date' => 'journal_entries.entry_date'], defaultSort: '-date');
    $ledger = app(PayablesService::class)->ledger($this->supplier, $query);

    // Newest first: the payment (AP debit, reduces payable) then the invoice receipt (AP credit).
    expect($ledger->total())->toBe(2)
        ->and($ledger->items()[0]->debit)->toBe(2_000_000)
        ->and($ledger->items()[0]->balance_after)->toBe(3_000_000)
        ->and($ledger->items()[1]->credit)->toBe(5_000_000)
        ->and($ledger->items()[1]->balance_after)->toBe(5_000_000);
});
