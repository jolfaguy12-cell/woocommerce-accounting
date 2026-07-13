<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Costing\Models\CostItem;
use App\Domain\Costing\Services\PurchaseInvoiceService;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Services\PayablesService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\SupplierCreditService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
});

it('records a manual retained-balance credit, debiting AP and crediting other income', function () {
    $adjustment = app(SupplierCreditService::class)->recordManualCredit($this->supplier, 300_000, 'انتقال مانده افتتاحیه', $this->admin->id);

    expect($adjustment->journalEntry->lines->firstWhere('debit', 300_000)->account->code)->toBe('2000')
        ->and($adjustment->journalEntry->lines->firstWhere('credit', 300_000)->account->code)->toBe('4900')
        ->and(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(-300_000);
});

it('clears the supplier advance FIRST when a refund arrives, before touching the payable', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);

    // Pay 300k against a 100k invoice: 100k settles the payable, 200k is an advance.
    app(PaymentRecorder::class)->pay($this->supplier, 300_000, $this->bank->id);

    $payment = app(PaymentRecorder::class)->receiveRefund($this->supplier, 150_000, $this->bank->id, null, 'bank_transfer', 'REF-1');

    // The refund is them giving the prepayment back, so it comes off the advance.
    // Crediting the payable instead would leave 200k still sitting on 1450 as an
    // asset we no longer have, AND manufacture a payable balance out of nothing.
    expect($payment->direction)->toBe('in')
        ->and($payment->method)->toBe('bank_transfer')
        ->and($payment->reference)->toBe('REF-1')
        ->and($payment->advance_amount)->toBe(150_000)
        ->and(app(PartyLedgerService::class)->balanceOn($this->supplier, AccountCode::SupplierAdvance))->toBe(50_000)
        ->and(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(0);
});

it('lets a refund larger than the advance fall through to the payable', function () {
    // No invoice: the whole 100k payment is an advance.
    app(PaymentRecorder::class)->pay($this->supplier, 100_000, $this->bank->id);

    app(PaymentRecorder::class)->receiveRefund($this->supplier, 150_000, $this->bank->id);

    // 100k clears the advance. The extra 50k is cash they handed us beyond any
    // prepayment we had made — we were not entitled to it, so we now OWE them
    // 50k, and the payable rises. (Unchanged behaviour for the excess; only the
    // part covered by an advance is what moved to 1450.)
    expect(app(PartyLedgerService::class)->balanceOn($this->supplier, AccountCode::SupplierAdvance))->toBe(0)
        ->and(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(50_000);
});

it('records a payment with method/reference and a refund/credit over HTTP, forbidding warehouse on all three', function () {
    $data = ['amount' => 100_000, 'bank_account_id' => $this->bank->id, 'method' => 'cash', 'reference' => 'REF-2'];

    $this->actingAs($this->admin)->post("/suppliers/{$this->supplier->id}/pay", $data)
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/suppliers/{$this->supplier->id}/refund", $data)
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/suppliers/{$this->supplier->id}/credit", ['amount' => 50_000, 'description' => 'یادداشت'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($this->warehouse)->post("/suppliers/{$this->supplier->id}/pay", $data)->assertForbidden();
    $this->actingAs($this->warehouse)->post("/suppliers/{$this->supplier->id}/refund", $data)->assertForbidden();
    $this->actingAs($this->warehouse)->post("/suppliers/{$this->supplier->id}/credit", ['amount' => 1, 'description' => 'x'])->assertForbidden();
});
