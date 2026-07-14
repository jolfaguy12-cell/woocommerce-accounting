<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PaymentPurpose;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Support\ReimbursementType;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\CreditOrderSettlement;
use App\Domain\Receivables\Models\PartyPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

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
            // The row's identity, resolved. Every public method below resolves the
            // same party before it builds its lines, so the row and the journal
            // entry always name one and the same id — this is the belt to their
            // braces, not a substitute for it.
            $party = Party::live($data['party']);
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
        $party = Party::live($party);
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
        $party = Party::live($party);
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
        $party = Party::live($party);
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
        $party = Party::live($party);
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

    /*
     |--------------------------------------------------------------------------
     | The employee's four money movements, and the settlement of an unpaid bill
     |--------------------------------------------------------------------------
     | Each of these pays down ONE account and no other. That is the whole design:
     | salary is 2300, an advance is 1400, money the employee laid out for the
     | company is 2350, an unpaid bill is 2000. They are never netted against each
     | other, because the company owes an employee their salary on payday whatever
     | else is true — a 2,000,000 advance does not make a 12,000,000 salary into a
     | 10,000,000 one, it makes it a 12,000,000 salary and a 2,000,000 debt, and
     | the next payroll run recovers the advance explicitly.
     |
     | Every one of them is capped at the balance it settles (`assertWithin`). An
     | uncapped payment does not clear a debt, it inverts it: pay an employee
     | 15,000,000 against a 12,000,000 salary and 2300 goes negative, which reads
     | as the employee owing the company 3,000,000 in salary — a sentence with no
     | meaning. The overpayment is a real event, but it is an advance or a loan,
     | and the operator has to say which.
     */

    /** «پرداخت حقوق» — Dr payroll payable (this employee), Cr the bank the money left. */
    public function paySalary(
        Party $party,
        int $amount,
        int $bankAccountId,
        ?string $accountingDate = null,
        ?string $reference = null,
        ?string $note = null,
        ?int $by = null,
    ): PartyPayment {
        $party = Party::live($party);

        $this->assertWithin($amount, $this->ledger->payrollPayable($party), 'مانده حقوق');

        return $this->record([
            'party' => $party,
            'direction' => 'out',
            'purpose' => PaymentPurpose::PayrollPayment,
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'accounting_date' => $accountingDate,
            'reference' => $reference,
            'note' => $note,
            'created_by' => $by,
            'description' => "پرداخت حقوق — {$party->name}",
        ], fn () => [
            ['account' => AccountCode::PayrollPayable, 'debit' => $amount, 'party_id' => $party->id, 'memo' => 'پرداخت حقوق'],
            ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount],
        ]);
    }

    /**
     * «مساعده» — salary paid before it is earned. An ASSET (1400): the employee owes
     * it back, and the next payroll run recovers it. Never posted to 2300, which
     * would make the company's salary debt shrink for a salary nobody has earned.
     */
    public function payEmployeeAdvance(
        Party $party,
        int $amount,
        int $bankAccountId,
        ?string $accountingDate = null,
        ?string $reference = null,
        ?string $note = null,
        ?int $by = null,
    ): PartyPayment {
        $party = Party::live($party);

        return $this->record([
            'party' => $party,
            'direction' => 'out',
            'purpose' => PaymentPurpose::EmployeeAdvance,
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'accounting_date' => $accountingDate,
            'reference' => $reference,
            'note' => $note,
            'created_by' => $by,
            'description' => "مساعده — {$party->name}",
        ], fn () => [
            ['account' => AccountCode::EmployeeAdvance, 'debit' => $amount, 'party_id' => $party->id, 'memo' => 'مساعده'],
            ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount],
        ]);
    }

    /**
     * «بازپرداخت هزینه کارمند» / «بازپرداخت هزینه شریک» — one operation, two accounts.
     *
     * The expense already posted the debt when it was recorded (ExpenseFundingSource
     * credited 2350 or 2600 and touched no bank account, because no company money
     * moved). This is the day we hand the money back: debit that same debt, credit
     * the bank it really left from.
     *
     * $expense is the optional link back to what is being paid for. It is not the
     * cap — a person may have funded a dozen small expenses and be paid back once —
     * so the ceiling is their OUTSTANDING BALANCE on the debt account, read from the
     * ledger, which is the only figure that cannot drift out of date.
     */
    public function reimburse(
        ReimbursementType $type,
        Party $party,
        int $amount,
        int $bankAccountId,
        ?string $accountingDate = null,
        ?string $reference = null,
        ?string $note = null,
        ?Expense $expense = null,
        ?int $by = null,
    ): PartyPayment {
        $party = Party::live($party);

        if (! $party->hasRole($type->requiredRole())) {
            throw new InvalidArgumentException(
                "«{$party->name}» نقش «{$type->requiredRole()->label()}» ندارد؛ «{$type->label()}» برای این طرف حساب ممکن نیست."
            );
        }

        if ($expense && $expense->fundingSource() !== $type->fundingSource()) {
            throw new InvalidArgumentException('هزینه انتخاب‌شده با این نوع بازپرداخت هم‌خوانی ندارد.');
        }

        if ($expense && $expense->funded_by_party_id !== null
            && Party::live($expense->funded_by_party_id)->id !== $party->id) {
            throw new InvalidArgumentException('هزینه انتخاب‌شده را این طرف حساب پرداخت نکرده است.');
        }

        $this->assertWithin($amount, $this->ledger->balanceOn($party, $type->debtAccount()), $type->balanceLabel());

        return $this->record([
            'party' => $party,
            'direction' => 'out',
            'purpose' => $type->purpose(),
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'accounting_date' => $accountingDate,
            'reference' => $reference,
            'note' => $note,
            'applied' => $expense,
            'created_by' => $by,
            'description' => "{$type->label()} — {$party->name}",
        ], fn () => [
            ['account' => $type->debtAccount(), 'debit' => $amount, 'party_id' => $party->id, 'memo' => $type->label()],
            ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount],
        ]);
    }

    /**
     * «تسویه هزینه پرداخت‌نشده» — paying a bill that was recorded as owed.
     *
     * Dr accounts payable (the creditor's own balance), Cr the bank. It creates NO
     * second expense: the cost was already recognised on the day the expense was
     * entered, and recognising it again on the day it is paid would double it — the
     * classic accrual mistake, and one that balances perfectly while it does it.
     *
     * $remaining comes from the caller (ExpenseSettlementService), which derives it
     * from journal lines rather than from any stored "paid" flag.
     */
    public function settleUnpaidExpense(
        Expense $expense,
        Party $party,
        int $amount,
        int $remaining,
        int $bankAccountId,
        ?string $accountingDate = null,
        ?string $reference = null,
        ?string $note = null,
        ?int $by = null,
    ): PartyPayment {
        $party = Party::live($party);

        $this->assertWithin($amount, $remaining, 'مانده پرداخت‌نشده این هزینه');

        return $this->record([
            'party' => $party,
            'direction' => 'out',
            'purpose' => PaymentPurpose::UnpaidExpenseSettlement,
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'accounting_date' => $accountingDate,
            'reference' => $reference,
            'note' => $note,
            'applied' => $expense,
            'created_by' => $by,
            'description' => "تسویه هزینه پرداخت‌نشده — {$expense->description}",
        ], fn () => [
            ['account' => AccountCode::AccountsPayable, 'debit' => $amount, 'party_id' => $party->id,
                'memo' => "تسویه هزینه #{$expense->id}"],
            ['account' => BankAccount::findOrFail($bankAccountId)->account_id, 'credit' => $amount],
        ]);
    }

    /**
     * Corrections are reversals. The original payment row and its entry stay exactly
     * as they were posted — an audit a year from now must find what actually
     * happened, including the mistake — and an opposing entry cancels the money.
     *
     * Because the reversal restores the balance it consumed, the settled totals and
     * the caps above all correct themselves: they are reads of the ledger, and the
     * ledger now says the payment was undone.
     */
    public function reverse(PartyPayment $payment, string $reason, User $by): PartyPayment
    {
        if (! $payment->journal_entry_id) {
            throw new InvalidArgumentException('این پرداخت سندی ندارد و قابل برگشت نیست.');
        }

        if ($payment->isReversed()) {
            throw new InvalidArgumentException('این پرداخت قبلاً برگشت خورده است.');
        }

        return DB::transaction(function () use ($payment, $reason, $by) {
            $reversal = $this->poster->reverse($payment->journalEntry, $reason, $by->id);

            $payment->forceFill([
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ])->save();

            return $payment->fresh();
        });
    }

    /**
     * Never pay out more than is owed. The message names the balance, because
     * "مبلغ بیش از حد مجاز است" tells the operator nothing they can act on.
     */
    private function assertWithin(int $amount, int $outstanding, string $label): void
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }

        $outstanding = max(0, $outstanding);

        if ($outstanding === 0) {
            throw new InvalidArgumentException("«{$label}» صفر است؛ چیزی برای پرداخت وجود ندارد.");
        }

        if ($amount > $outstanding) {
            throw new InvalidArgumentException(
                "مبلغ بیشتر از «{$label}» است: حداکثر ".number_format($outstanding).' تومان.'
            );
        }
    }
}
