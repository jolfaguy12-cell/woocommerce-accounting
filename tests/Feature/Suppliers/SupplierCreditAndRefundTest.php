<?php

use App\Domain\Accounting\Models\Party;
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

it('records a refund from the supplier, debiting the bank account and crediting AP back toward zero', function () {
    $service = app(PurchaseInvoiceService::class);
    $item = CostItem::create(['name' => 'اسپری']);
    $invoice = $service->create([
        'supplier_party_id' => $this->supplier->id,
        'invoice_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'lines' => [['cost_item_id' => $item->id, 'qty' => 1, 'unit_price' => 100_000]],
    ]);
    $service->receive($invoice, [$invoice->lines->first()->id => 1]);
    app(PaymentRecorder::class)->pay($this->supplier, 300_000, $this->bank->id); // overpay -> -200,000 credit balance

    $payment = app(PaymentRecorder::class)->receiveRefund($this->supplier, 150_000, $this->bank->id, null, 'bank_transfer', 'REF-1');

    expect($payment->direction)->toBe('in')
        ->and($payment->method)->toBe('bank_transfer')
        ->and($payment->reference)->toBe('REF-1')
        ->and(app(PayablesService::class)->partyPayableBalance($this->supplier))->toBe(-50_000);
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
