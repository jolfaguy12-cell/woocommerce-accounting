<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Services\CreditOrderService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\ReceivablesService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->customer = Party::createWithRole('customer', ['name' => 'مشتری اعتباری']);
});

it('opens a manual credit order posting AR against sales', function () {
    $credit = app(CreditOrderService::class)->openManual(
        $this->customer, 1_000_000, 'فروش اعتباری حضوری', Carbon::parse('2026-08-01', 'Asia/Tehran'),
    );

    expect($credit->total_due)->toBe(1_000_000)
        ->and($credit->remaining())->toBe(1_000_000)
        ->and($credit->journalEntry->lines->sum('debit'))->toBe(1_000_000);
});

it('settles credit orders partially, tracking the remaining balance', function () {
    $credit = app(CreditOrderService::class)->openManual($this->customer, 1_000_000, 'اعتباری', now());
    $payments = app(PaymentRecorder::class);

    $payments->receive($this->customer, 400_000, $this->bank->id, $credit);
    expect($credit->refresh()->remaining())->toBe(600_000)
        ->and($credit->status)->toBe('open');

    $payments->receive($this->customer, 600_000, $this->bank->id, $credit);
    expect($credit->refresh()->remaining())->toBe(0)
        ->and($credit->status)->toBe('settled');
});

it('keeps overpayments as customer credit balance', function () {
    $credit = app(CreditOrderService::class)->openManual($this->customer, 500_000, 'اعتباری', now());

    $payment = app(PaymentRecorder::class)->receive($this->customer, 700_000, $this->bank->id, $credit);

    // 500k settles AR, 200k goes to customer credit (liability 2400)
    $lines = $payment->journalEntry->lines;
    expect($credit->refresh()->status)->toBe('settled')
        ->and($lines->firstWhere('account_id', $this->bank->account_id)->debit)->toBe(700_000)
        ->and($lines->sum('credit'))->toBe(700_000)
        ->and(app(ReceivablesService::class)->customerCreditBalance($this->customer))->toBe(200_000);
});

it('reports receivable aging with overdue flags', function () {
    $service = app(CreditOrderService::class);
    $service->openManual($this->customer, 300_000, 'سررسید گذشته', Carbon::now('Asia/Tehran')->subDays(40));
    $service->openManual($this->customer, 200_000, 'جاری', Carbon::now('Asia/Tehran')->addDays(10));

    $aging = app(ReceivablesService::class)->aging();
    $row = collect($aging)->firstWhere('party_id', $this->customer->id);

    expect($row['total_due'])->toBe(500_000)
        ->and($row['overdue'])->toBe(300_000);
});
