<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\CreditOrderSettlement;
use App\Domain\Receivables\Models\PartyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRecorder
{
    private const AR = '1200';

    private const CUSTOMER_CREDIT = '2400';

    private const AP = '2000';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly CreditOrderAllocator $allocator,
    ) {}

    /**
     * Customer payment in: settles AR up to what is owed; any excess is held
     * as customer credit (liability), never lost.
     */
    public function receive(Party $party, int $amount, int $bankAccountId, ?CreditOrder $creditOrder = null, ?int $by = null): PartyPayment
    {
        return DB::transaction(function () use ($party, $amount, $bankAccountId, $creditOrder, $by) {
            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => 'in',
                'amount' => $amount,
                'bank_account_id' => $bankAccountId,
                'applied_type' => $creditOrder?->getMorphClass(),
                'applied_id' => $creditOrder?->id,
                'paid_at' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'created_by' => $by,
            ]);

            $settled = $amount;
            $excess = 0;

            if ($creditOrder) {
                $settled = min($amount, $creditOrder->remaining());
                $excess = $amount - $settled;

                $creditOrder->update([
                    'paid_total' => $creditOrder->paid_total + $settled,
                    'status' => $creditOrder->paid_total + $settled >= $creditOrder->total_due ? 'settled' : 'open',
                ]);
            }

            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => self::AR, 'credit' => $settled, 'party_id' => $party->id],
            ];
            if ($excess > 0) {
                $lines[] = ['account' => self::CUSTOMER_CREDIT, 'credit' => $excess, 'party_id' => $party->id];
            }

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "دریافت از {$party->name}",
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $by,
            ], $lines);

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines');
        });
    }

    /**
     * Customer payment in, allocated across ALL their open orders oldest-first
     * (see CreditOrderAllocator) rather than one specific order — this is the
     * "one-click settle a customer's balance" action, usable from either the
     * order page or the customer page since it's the same underlying party.
     */
    public function receiveForCustomer(Party $party, int $amount, int $bankAccountId, ?int $by = null): PartyPayment
    {
        return DB::transaction(function () use ($party, $amount, $bankAccountId, $by) {
            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => 'in',
                'amount' => $amount,
                'bank_account_id' => $bankAccountId,
                'paid_at' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'created_by' => $by,
            ]);

            $allocation = $this->allocator->apply($party, $amount);
            $settled = $allocation['applied'];
            $excess = $amount - $settled;

            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
            ];
            if ($settled > 0) {
                $lines[] = ['account' => self::AR, 'credit' => $settled, 'party_id' => $party->id];
            }
            if ($excess > 0) {
                $lines[] = ['account' => self::CUSTOMER_CREDIT, 'credit' => $excess, 'party_id' => $party->id];
            }

            // A payment is always dated today, never backdated — if the current
            // period is itself locked, that's a real "an accountant closed the
            // books mid-month" situation with no safe automatic fix, so this is
            // left to throw PeriodLockedException and surface to the caller
            // (see CustomerController) rather than silently retrying the same date.
            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "دریافت از {$party->name}",
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $by,
            ], $lines);

            foreach ($allocation['lines'] as $line) {
                CreditOrderSettlement::create([
                    'credit_order_id' => $line['credit_order']->id,
                    'source_type' => $payment->getMorphClass(),
                    'source_id' => $payment->id,
                    'amount' => $line['amount'],
                ]);

                // The allocator already flipped credit_orders.status to 'settled' in
                // memory when fully paid — mirror that onto the linked order so the
                // order page stops showing "پرداخت نشده" once its debt is actually paid.
                $creditOrder = $line['credit_order'];
                if ($creditOrder->status === 'settled' && $creditOrder->order_id) {
                    Order::whereKey($creditOrder->order_id)->update([
                        'payment_status' => 'paid',
                        'date_paid' => $payment->paid_at,
                    ]);
                }
            }

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines', 'settlements.creditOrder');
        });
    }

    /**
     * Payment out to a supplier: debits AP, credits the paying bank account.
     * No cap at the current payable balance — paying more than currently owed
     * is a real event (an advance), and simply drives the AP balance negative
     * for this party rather than needing a separate prepaid-asset account.
     */
    public function pay(Party $party, int $amount, int $bankAccountId, ?int $by = null, ?string $method = null, ?string $reference = null): PartyPayment
    {
        return DB::transaction(function () use ($party, $amount, $bankAccountId, $by, $method, $reference) {
            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => 'out',
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'bank_account_id' => $bankAccountId,
                'paid_at' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "پرداخت به {$party->name}",
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $by,
            ], [
                ['account' => self::AP, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount, 'party_id' => $party->id],
            ]);

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines');
        });
    }

    /**
     * A supplier refunding money back to us — the mirror of pay(): credits AP
     * (reducing or reversing a negative/credit balance), debits the receiving
     * bank account. Same generic party_payments table, direction 'in'.
     */
    public function receiveRefund(Party $party, int $amount, int $bankAccountId, ?int $by = null, ?string $method = null, ?string $reference = null): PartyPayment
    {
        return DB::transaction(function () use ($party, $amount, $bankAccountId, $by, $method, $reference) {
            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => 'in',
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'bank_account_id' => $bankAccountId,
                'paid_at' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'created_by' => $by,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "بازپرداخت از {$party->name}",
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $by,
            ], [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => self::AP, 'credit' => $amount, 'party_id' => $party->id],
            ]);

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines');
        });
    }
}
