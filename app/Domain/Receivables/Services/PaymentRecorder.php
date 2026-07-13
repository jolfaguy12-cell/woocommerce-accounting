<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PaymentPurpose;
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
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly CreditOrderAllocator $allocator,
        private readonly PartyLedgerService $ledger,
    ) {}

    /**
     * The one place a party payment becomes a row and an entry.
     *
     * Every method below delegates here, so a payment cannot be half-recorded (a
     * row with no entry, or an entry with no row): they are created together, in
     * one transaction, with one idempotency key. The *lines* stay with the caller
     * — a customer receipt and a partner's drawings genuinely post to different
     * accounts, and pretending one mapping covers both is how a payment ends up
     * on the wrong side of the ledger.
     *
     * $data: party, direction, amount, bank_account_id, description
     *        [purpose, advance_amount, applied, method, reference, note,
     *         accounting_date, party_bank_account_id, created_by]
     *
     * @param  callable(PartyPayment): array  $lines
     */
    public function record(array $data, callable $lines): PartyPayment
    {
        return DB::transaction(function () use ($data, $lines) {
            /** @var Party $party */
            $party = $data['party'];
            $purpose = $data['purpose'] ?? null;
            $applied = $data['applied'] ?? null;

            // An explicit accounting_date lets a payment be posted for the day it
            // really happened. Absent — which is every existing caller — it stays
            // "today", exactly as before.
            $entryDate = isset($data['accounting_date'])
                ? Carbon::parse($data['accounting_date'], JalaliPeriod::TIMEZONE)
                : Carbon::now(JalaliPeriod::TIMEZONE);

            $payment = PartyPayment::create([
                'uuid' => (string) Str::uuid(),
                'party_id' => $party->id,
                'direction' => $data['direction'],
                'purpose' => $purpose instanceof PaymentPurpose ? $purpose->value : $purpose,
                'amount' => $data['amount'],
                'advance_amount' => $data['advance_amount'] ?? 0,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'bank_account_id' => $data['bank_account_id'],
                'party_bank_account_id' => $data['party_bank_account_id'] ?? null,
                'applied_type' => $applied?->getMorphClass(),
                'applied_id' => $applied?->id,
                'paid_at' => $entryDate->toDateString(),
                'accounting_date' => isset($data['accounting_date']) ? $entryDate->toDateString() : null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $entry = $this->poster->post([
                'entry_date' => $entryDate,
                'description' => $data['description'],
                'idempotency_key' => "payment:{$payment->uuid}",
                'source' => $payment,
                'created_by' => $data['created_by'] ?? null,
            ], $lines($payment));

            $payment->update(['journal_entry_id' => $entry->id]);

            return $payment->load('journalEntry.lines');
        });
    }

    /**
     * Customer payment in: settles AR up to what is owed; any excess is held
     * as customer credit (liability), never lost.
     */
    public function receive(Party $party, int $amount, int $bankAccountId, ?CreditOrder $creditOrder = null, ?int $by = null): PartyPayment
    {
        $settled = $amount;
        $excess = 0;

        if ($creditOrder) {
            $settled = min($amount, $creditOrder->remaining());
            $excess = $amount - $settled;
        }

        return $this->record([
            'party' => $party,
            'direction' => 'in',
            'purpose' => PaymentPurpose::CustomerReceipt,
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'applied' => $creditOrder,
            'created_by' => $by,
            'description' => "دریافت از {$party->name}",
        ], function () use ($party, $bankAccountId, $amount, $settled, $excess, $creditOrder) {
            if ($creditOrder) {
                $creditOrder->update([
                    'paid_total' => $creditOrder->paid_total + $settled,
                    'status' => $creditOrder->paid_total + $settled >= $creditOrder->total_due ? 'settled' : 'open',
                ]);
            }

            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
                ['account' => AccountCode::AccountsReceivable, 'credit' => $settled, 'party_id' => $party->id],
            ];

            if ($excess > 0) {
                $lines[] = ['account' => AccountCode::CustomerCredit, 'credit' => $excess, 'party_id' => $party->id];
            }

            return $lines;
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
        $allocation = $this->allocator->apply($party, $amount);
        $settled = $allocation['applied'];
        $excess = $amount - $settled;

        // A payment is always dated today, never backdated — if the current
        // period is itself locked, that's a real "an accountant closed the
        // books mid-month" situation with no safe automatic fix, so this is
        // left to throw PeriodLockedException and surface to the caller
        // (see CustomerController) rather than silently retrying the same date.
        $payment = $this->record([
            'party' => $party,
            'direction' => 'in',
            'purpose' => PaymentPurpose::CustomerReceipt,
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'created_by' => $by,
            'description' => "دریافت از {$party->name}",
        ], function (PartyPayment $payment) use ($party, $bankAccountId, $amount, $settled, $excess, $allocation) {
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

            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
            ];
            if ($settled > 0) {
                $lines[] = ['account' => AccountCode::AccountsReceivable, 'credit' => $settled, 'party_id' => $party->id];
            }
            if ($excess > 0) {
                $lines[] = ['account' => AccountCode::CustomerCredit, 'credit' => $excess, 'party_id' => $party->id];
            }

            return $lines;
        });

        return $payment->load('settlements.creditOrder');
    }

    /**
     * Payment out to a supplier, split between settling what we owe and paying
     * ahead of it.
     *
     * Anything above the outstanding payable is an ADVANCE and lands on 1450, not
     * on 2000. Previously the excess simply drove the payable negative, which made
     * a prepayment — a real asset, money we are owed goods for — read as a
     * *negative liability*: it netted silently against the next invoice, never
     * appeared as something to chase if the supplier vanished, and no report could
     * separate "we owe them nothing" from "they owe us goods". Now the two are
     * different accounts, and both are true at once.
     *
     * The signature is unchanged: every existing caller keeps working.
     */
    public function pay(Party $party, int $amount, int $bankAccountId, ?int $by = null, ?string $method = null, ?string $reference = null): PartyPayment
    {
        $outstanding = max(0, $this->ledger->supplierPayable($party));
        $settled = min($amount, $outstanding);
        $advance = $amount - $settled;

        return $this->record([
            'party' => $party,
            'direction' => 'out',
            // A payment that settles nothing is purely an advance; one that settles
            // something is an invoice settlement that may also run ahead.
            'purpose' => $settled > 0 ? PaymentPurpose::SupplierInvoiceSettlement : PaymentPurpose::SupplierAdvance,
            'amount' => $amount,
            'advance_amount' => $advance,
            'method' => $method,
            'reference' => $reference,
            'bank_account_id' => $bankAccountId,
            'created_by' => $by,
            'description' => "پرداخت به {$party->name}",
        ], function () use ($party, $bankAccountId, $amount, $settled, $advance) {
            $lines = [];

            if ($settled > 0) {
                $lines[] = ['account' => AccountCode::AccountsPayable, 'debit' => $settled, 'party_id' => $party->id];
            }
            if ($advance > 0) {
                $lines[] = [
                    'account' => AccountCode::SupplierAdvance,
                    'debit' => $advance,
                    'party_id' => $party->id,
                    'memo' => 'پیش‌پرداخت (مازاد بر بدهی جاری)',
                ];
            }

            $lines[] = ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount, 'party_id' => $party->id];

            return $lines;
        });
    }

    /**
     * A supplier refunding money back to us — the mirror of pay().
     *
     * The advance is cleared FIRST. If we are holding a prepayment with them, a
     * refund is them giving that prepayment back; crediting the payable instead
     * would leave the advance still sitting on 1450 as an asset we no longer have,
     * while manufacturing a payable balance out of nothing. Only what exceeds the
     * advance touches 2000.
     *
     * The signature is unchanged: every existing caller keeps working.
     */
    public function receiveRefund(Party $party, int $amount, int $bankAccountId, ?int $by = null, ?string $method = null, ?string $reference = null): PartyPayment
    {
        $advanceHeld = max(0, $this->ledger->balanceOn($party, AccountCode::SupplierAdvance));
        $fromAdvance = min($amount, $advanceHeld);
        $toPayable = $amount - $fromAdvance;

        return $this->record([
            'party' => $party,
            'direction' => 'in',
            'purpose' => PaymentPurpose::SupplierRefund,
            'amount' => $amount,
            'advance_amount' => $fromAdvance,
            'method' => $method,
            'reference' => $reference,
            'bank_account_id' => $bankAccountId,
            'created_by' => $by,
            'description' => "بازپرداخت از {$party->name}",
        ], function () use ($party, $bankAccountId, $amount, $fromAdvance, $toPayable) {
            $lines = [
                ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'debit' => $amount, 'party_id' => $party->id],
            ];

            if ($fromAdvance > 0) {
                $lines[] = [
                    'account' => AccountCode::SupplierAdvance,
                    'credit' => $fromAdvance,
                    'party_id' => $party->id,
                    'memo' => 'برگشت پیش‌پرداخت',
                ];
            }
            if ($toPayable > 0) {
                $lines[] = ['account' => AccountCode::AccountsPayable, 'credit' => $toPayable, 'party_id' => $party->id];
            }

            return $lines;
        });
    }
}
