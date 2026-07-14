<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Orders\Services\OrderIngestPipeline;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Services\CreditOrderService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\ReceivablesService;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([ChartOfAccountsSeeder::class, ChannelSeeder::class]);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->customer = Party::createWithRole('customer', ['name' => 'مشتری']);
});

function settlementTestOrder(int $id, string $date): array
{
    return [
        'id' => $id, 'status' => 'completed', 'currency' => 'IRT', 'total' => 500000,
        'discount_total' => 0, 'shipping_total' => 0, 'created_via' => 'checkout',
        'date_created' => $date.'T10:00:00', 'date_modified' => $date.'T10:00:00', 'meta' => [],
        'line_items' => [['id' => $id * 10, 'name' => 'کالا', 'quantity' => 1, 'subtotal' => 500000, 'total' => 500000, 'product_id' => 1, 'variation_id' => null]],
    ];
}

it('settles the oldest order first, then the next, exactly as PaymentRecorder::receive() already does for a single order', function () {
    $older = app(OrderIngestPipeline::class)->ingest(7101, settlementTestOrder(7101, '2026-07-01'), 'manual');
    $newer = app(OrderIngestPipeline::class)->ingest(7102, settlementTestOrder(7102, '2026-07-05'), 'manual');

    $olderCredit = CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $older->id, 'party_id' => $this->customer->id, 'total_due' => 100, 'paid_total' => 0]);
    $newerCredit = CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $newer->id, 'party_id' => $this->customer->id, 'total_due' => 300, 'paid_total' => 0]);

    $payment = app(PaymentRecorder::class)->receiveForCustomer($this->customer, 200, $this->bank->id);

    expect($olderCredit->refresh()->status)->toBe('settled')
        ->and($newerCredit->refresh()->remaining())->toBe(200)
        ->and($payment->journalEntry->lines->sum('debit'))->toBe(200)
        ->and($payment->journalEntry->lines->sum('credit'))->toBe(200)
        ->and($payment->settlements)->toHaveCount(2)
        ->and($payment->settlements->firstWhere('credit_order_id', $olderCredit->id)->amount)->toBe(100)
        ->and($payment->settlements->firstWhere('credit_order_id', $newerCredit->id)->amount)->toBe(100);
});

it('flips the linked order to paid once its credit order is fully settled by a real payment', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7110, settlementTestOrder(7110, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 500000, 'paid_total' => 0]);
    expect($order->fresh()->payment_status)->not->toBe('paid');

    app(PaymentRecorder::class)->receiveForCustomer($this->customer, 500000, $this->bank->id);

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and($order->fresh()->date_paid)->not->toBeNull();
});

it('does not flip the order to paid on a partial settlement', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7111, settlementTestOrder(7111, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 500000, 'paid_total' => 0]);

    app(PaymentRecorder::class)->receiveForCustomer($this->customer, 200000, $this->bank->id);

    expect($order->fresh()->payment_status)->not->toBe('paid');
});

it('does not flip the order to paid on a write-off, only a real payment', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7112, settlementTestOrder(7112, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 500000, 'paid_total' => 0]);

    app(CreditOrderService::class)->writeOff($this->customer, 500000, 'مشتری غیرقابل دسترس');

    expect($order->fresh()->payment_status)->not->toBe('paid');
});

it('routes leftover past all open orders to customer credit', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7103, settlementTestOrder(7103, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 100, 'paid_total' => 0]);

    app(PaymentRecorder::class)->receiveForCustomer($this->customer, 300, $this->bank->id);

    expect(app(ReceivablesService::class)->customerCreditBalance($this->customer))->toBe(200);
});

it('does not disturb PaymentRecorder::receive(), the single-order path fast-forms.tsx still uses', function () {
    $credit = app(CreditOrderService::class)->openManual($this->customer, 1_000_000, 'اعتباری', now());

    app(PaymentRecorder::class)->receive($this->customer, 400_000, $this->bank->id, $credit);

    expect($credit->refresh()->remaining())->toBe(600_000);
});

it('writes off a customer balance as bad debt expense, settling their oldest open order', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7104, settlementTestOrder(7104, '2026-07-01'), 'manual');
    $credit = CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 500000, 'paid_total' => 0]);

    $writeOff = app(CreditOrderService::class)->writeOff($this->customer, 500000, 'مشتری غیرقابل دسترس');

    expect($credit->refresh()->status)->toBe('settled')
        ->and($writeOff->journalEntry->lines->firstWhere('debit', 500000))->not->toBeNull()
        ->and($writeOff->journalEntry->lines->firstWhere('credit', 500000)->party_id)->toBe($this->customer->id)
        ->and($writeOff->settlements)->toHaveCount(1);
});

it('caps a write-off at the actual open balance rather than over-writing-off', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7105, settlementTestOrder(7105, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 100, 'paid_total' => 0]);

    $writeOff = app(CreditOrderService::class)->writeOff($this->customer, 999999, 'بیش از حد');

    expect($writeOff->amount)->toBe(100);
});

it('throws when there is nothing open to write off', function () {
    app(CreditOrderService::class)->writeOff($this->customer, 100, 'هیچ چیز');
})->throws(InvalidArgumentException::class);

it('reports the net balance as debtor, creditor, or settled', function () {
    $order = app(OrderIngestPipeline::class)->ingest(7106, settlementTestOrder(7106, '2026-07-01'), 'manual');
    CreditOrder::create(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'party_id' => $this->customer->id, 'total_due' => 300, 'paid_total' => 0]);
    $service = app(ReceivablesService::class);

    expect($service->partyOpenBalance($this->customer))->toBe(300)
        ->and($service->partyNetBalance($this->customer))->toBe(300); // debtor

    app(PaymentRecorder::class)->receiveForCustomer($this->customer, 500, $this->bank->id); // 300 settles, 200 becomes credit

    expect($service->partyOpenBalance($this->customer))->toBe(0)
        ->and($service->partyNetBalance($this->customer))->toBe(-200); // creditor
});
