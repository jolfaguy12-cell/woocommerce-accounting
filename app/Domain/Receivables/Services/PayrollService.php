<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Models\PayrollItem;
use App\Domain\Receivables\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Payroll, kept deliberately small: accrue what was earned, pay what is owed,
 * reverse what was wrong. No attendance, no tax, no insurance, no benefits — those
 * are an HR system, and this is an accounting one.
 *
 * The two halves are separate operations on purpose, because they are separate
 * events, and 2300 is what makes that true instead of just declared:
 *
 *   ACCRUAL («ثبت حقوق دوره») — the salary is EARNED. Dr salary expense (6100),
 *   Cr payroll payable (2300) per employee. No cash moves; the company now owes
 *   the money. This is what makes a month's profit include the month's salaries
 *   even when payday falls in the next month.
 *
 *   PAYMENT («پرداخت حقوق») — the money is HANDED OVER. Dr that employee's payroll
 *   payable, Cr the bank. The expense is not touched: it was recognised when the
 *   salary was earned, and recognising it again here would double every salary in
 *   the books while balancing perfectly. Salary expense is NEVER debited straight
 *   to a bank account — 2300 is the one bridge between "earned" and "paid", and
 *   skipping it is exactly how a month's salary stops being traceable to who it
 *   was for.
 *
 * «پرداخت هم‌زمان» does not blur this. It is the SAME payment operation, posted
 * as its OWN journal entry, inside the SAME database transaction as the accrual —
 * so the two are atomic (either both land or neither does) without becoming one
 * entry. They stay linked only by a shared correlation id and by the payment
 * pointing back at the run it was paid alongside.
 *
 * The payment itself is recorded by PaymentRecorder, the same service that records
 * every other party payment. There is no payroll payment table and no payroll
 * ledger: a salary payment is a payment, and it belongs where payments live.
 */
class PayrollService
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly PaymentRecorder $payments,
        private readonly PartyLedgerService $ledger,
    ) {}

    /**
     * «ثبت حقوق دوره» — accrue one period's salaries, optionally paying some or
     * all of it immediately («پرداخت هم‌زمان»).
     *
     * The payable and advance lines are per EMPLOYEE and carry their party_id. They
     * used to be single aggregate lines with no party at all, which balanced
     * perfectly and told you nothing: the company's total salary debt was right,
     * while every individual employee's «مانده حقوق» read zero, because not one
     * journal line said whose salary it was. An unattributable balance is not a
     * balance — you cannot pay it, dispute it, or reconcile it.
     *
     * The accrual ALWAYS happens — it does not become optional or conditional on
     * anything about payment. «پرداخت هم‌زمان» is additive: for an item that
     * requests it, a SEPARATE payment entry posts (Dr this employee's 2300, Cr the
     * chosen bank), inside this same DB transaction, so it is atomic with the
     * accrual — if the payment fails validation (e.g. its own amount exceeds what
     * was just accrued), the whole call throws and NOTHING from this run is
     * posted, accrual included. Both entries carry the run's uuid as their
     * correlation id, so they can be traced back to the same event without ever
     * becoming one entry with two meanings.
     *
     * @param  array<int, array{
     *     employee_id: int, gross: int, advances_deducted?: int,
     *     immediate_payment?: array{
     *         amount: int, bank_account_id: int, accounting_date: string,
     *         method: string, reference: string, note: string,
     *     }|null,
     * }>  $items
     */
    public function post(string $jalaliPeriod, array $items, ?int $by = null, ?string $notes = null): PayrollRun
    {
        $prepared = $this->prepare($jalaliPeriod, $items);

        return DB::transaction(function () use ($jalaliPeriod, $prepared, $by, $notes) {
            $run = PayrollRun::create([
                'uuid' => (string) Str::uuid(),
                'jalali_period' => $jalaliPeriod,
                'run_date' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
                'status' => PayrollRun::DRAFT,
                'notes' => $notes,
                'created_by' => $by,
            ]);

            $gross = 0;
            $payableLines = [];
            $advanceLines = [];

            foreach ($prepared as $item) {
                $run->items()->create([
                    'employee_id' => $item['employee_id'],
                    'gross' => $item['gross'],
                    'advances_deducted' => $item['advances_deducted'],
                    'net' => $item['net'],
                ]);

                $gross += $item['gross'];

                // The advance is recovered from THIS employee's 1400 balance, not from
                // "payroll" at large: it is money they were lent, and it comes back out
                // of the salary they earned.
                if ($item['advances_deducted'] > 0) {
                    $advanceLines[] = [
                        'account' => AccountCode::EmployeeAdvance,
                        'credit' => $item['advances_deducted'],
                        'party_id' => $item['party_id'],
                        'memo' => 'بازپس‌گیری مساعده',
                    ];
                }

                if ($item['net'] > 0) {
                    $payableLines[] = [
                        'account' => AccountCode::PayrollPayable,
                        'credit' => $item['net'],
                        'party_id' => $item['party_id'],
                        'memo' => 'حقوق تحقق‌یافته',
                    ];
                }
            }

            $lines = array_merge(
                [['account' => AccountCode::Payroll, 'debit' => $gross, 'memo' => "حقوق دوره {$jalaliPeriod}"]],
                $advanceLines,
                $payableLines,
            );

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "حقوق دوره {$jalaliPeriod}",
                'idempotency_key' => "payroll:{$run->uuid}",
                'source' => $run,
                'correlation_id' => $run->uuid,
                'created_by' => $by,
            ], $lines);

            $run->update([
                'status' => PayrollRun::POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ]);

            // «پرداخت هم‌زمان» — a SEPARATE entry per employee who opted in, posted
            // now that the accrual (and therefore this employee's payable) is on the
            // books. Still inside the same transaction: PaymentRecorder::paySalary()
            // re-checks the cap against the party's true 2300 balance, and if THAT
            // throws, the accrual just posted above rolls back with it — the run
            // never ends up half-posted.
            foreach ($prepared as $item) {
                if ($item['immediate_payment'] === null) {
                    continue;
                }

                $ip = $item['immediate_payment'];

                $this->payments->paySalary(
                    party: Party::find($item['party_id']),
                    amount: $ip['amount'],
                    bankAccountId: $ip['bank_account_id'],
                    accountingDate: $ip['accounting_date'],
                    reference: $ip['reference'],
                    note: $ip['note'],
                    by: $by,
                    method: $ip['method'],
                    applied: $run,
                    correlationId: $run->uuid,
                );
            }

            return $run->load('journalEntry.lines', 'items', 'payments');
        });
    }

    /**
     * «پرداخت حقوق» — hand the money over, as its OWN transaction on the
     * employee's own page. Capped at what this employee is actually owed
     * (PaymentRecorder::paySalary), because paying more salary than was earned
     * does not clear a debt, it inverts one: 2300 goes negative and reads as the
     * employee owing the company salary, which is not a thing that can be true. An
     * overpayment is a real event — but it is an advance, and it has its own button.
     *
     * Unlike «پرداخت هم‌زمان» (post()), this is not tied to any one run: the
     * employee's payable is a single running balance across every accrual, and a
     * standalone payment settles that balance, not one run's slice of it. $forRun
     * is an OPTIONAL note of which run the operator has in mind — purely for the
     * audit trail on that run's own page — and is refused if it names a run that
     * was never this employee's, so a payment can never be filed against a
     * stranger's payroll history by a stray id.
     */
    public function paySalary(
        Party $party,
        int $amount,
        int $bankAccountId,
        ?string $accountingDate = null,
        ?string $reference = null,
        ?string $note = null,
        ?int $by = null,
        ?string $method = null,
        ?PayrollRun $forRun = null,
    ): PartyPayment {
        $party = Party::live($party);

        if (! $party->hasRole('employee')) {
            throw new InvalidArgumentException("«{$party->name}» نقش کارمند ندارد.");
        }

        if ($forRun && ! $forRun->items()->whereHas(
            'employee.party', fn ($q) => $q->whereIn('id', $party->identityIds())
        )->exists()) {
            throw new InvalidArgumentException('این لیست حقوق مربوط به این کارمند نیست.');
        }

        return $this->payments->paySalary(
            $party, $amount, $bankAccountId, $accountingDate, $reference, $note, $by,
            method: $method, applied: $forRun,
        );
    }

    /**
     * Corrections are reversals — never an edit, never a delete. The run and its
     * entry stay exactly as posted; an opposing entry cancels them, carrying the
     * reason and the person who gave it.
     *
     * A run whose salaries have already been PAID cannot be reversed from here: the
     * money is gone, and un-accruing the debt it settled would leave the payment
     * hanging against a payable that no longer exists. Reverse the payments first.
     */
    public function reverse(PayrollRun $run, string $reason, User $by): PayrollRun
    {
        if (! $run->isPosted()) {
            throw new OperationStateException('فقط لیست حقوق ثبت‌شده قابل برگشت است.');
        }

        $this->assertNoSalaryPaid($run);

        return DB::transaction(function () use ($run, $reason, $by) {
            $reversal = $this->poster->reverse($run->journalEntry, $reason, $by->id);

            $run->forceFill([
                'status' => PayrollRun::REVERSED,
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ])->save();

            return $run->fresh(['items', 'journalEntry']);
        });
    }

    /**
     * Employees with the role, active first — what «ثبت حقوق دوره» proposes rows for.
     * An employee whose party has been merged away is not offered: they are not a
     * separate person any more.
     *
     * @return Collection<int, Employee>
     */
    public function payableEmployees()
    {
        return Employee::query()
            ->with('party')
            ->where('is_active', true)
            ->whereHas('party', fn ($q) => $q->notMerged())
            ->get()
            ->sortBy(fn (Employee $e) => $e->party?->name)
            ->values();
    }

    /**
     * Has this employee already been accrued for this period?
     *
     * The check that matters most, and the one that is hardest to see: a second
     * accrual posts a perfectly balanced entry and doubles somebody's salary debt.
     * A REVERSED run does not count — that is exactly the case where the period has
     * to be re-run.
     */
    public function alreadyAccrued(int $employeeId, string $jalaliPeriod): bool
    {
        return PayrollItem::where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q
                ->where('jalali_period', $jalaliPeriod)
                ->where('status', PayrollRun::POSTED))
            ->exists();
    }

    /**
     * Validate the whole run before a single row is written: an accrual that fails
     * halfway leaves a payroll run with some employees in it and some not, and no
     * way to tell which. Every «پرداخت هم‌زمان» sub-field is validated here too,
     * for the same reason — the transaction in post() still re-checks the amount
     * against the live ledger once the accrual is on the books, but a run that
     * fails on employee #4's payment must never have already written employees
     * #1–3 to the database, which is only true if the check happens before any
     * of them do.
     *
     * @return array<int, array{
     *     employee_id: int, party_id: int, gross: int, advances_deducted: int, net: int,
     *     immediate_payment: array{
     *         amount: int, bank_account_id: int, accounting_date: string,
     *         method: string, reference: string, note: string,
     *     }|null,
     * }>
     */
    private function prepare(string $jalaliPeriod, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('حداقل یک کارمند برای ثبت حقوق دوره لازم است.');
        }

        $prepared = [];
        $seen = [];

        foreach ($items as $item) {
            $employeeId = (int) $item['employee_id'];
            $gross = (int) $item['gross'];
            $advance = (int) ($item['advances_deducted'] ?? 0);

            if (in_array($employeeId, $seen, true)) {
                throw new InvalidArgumentException('هر کارمند فقط یک بار در یک لیست حقوق می‌آید.');
            }
            $seen[] = $employeeId;

            $employee = Employee::with('party')->findOrFail($employeeId);

            // The whole point of the per-employee payable line. A payroll line with no
            // party is a debt the company owes to nobody in particular — it balances,
            // and it can never be paid, disputed or reconciled.
            $party = $employee->party
                ?? throw new InvalidArgumentException('کارمند بدون طرف حساب است و حقوق او قابل ثبت نیست.');

            $party = $party->canonical();

            if ($gross < 1) {
                throw new InvalidArgumentException("مبلغ حقوق «{$party->name}» باید بزرگ‌تر از صفر باشد.");
            }

            if ($advance < 0 || $advance > $gross) {
                throw new InvalidArgumentException(
                    "کسر مساعده «{$party->name}» نمی‌تواند از حقوق ناخالص بیشتر باشد."
                );
            }

            // You cannot recover an advance the employee does not hold: crediting 1400
            // below what they actually took would turn their advance NEGATIVE, which
            // reads as the company owing them an advance — a debt that does not exist.
            $held = max(0, $this->ledger->employeeAdvance($party));

            if ($advance > $held) {
                throw new InvalidArgumentException(
                    "کسر مساعده «{$party->name}» بیشتر از مانده مساعده اوست: حداکثر ".number_format($held).' تومان.'
                );
            }

            if ($this->alreadyAccrued($employeeId, $jalaliPeriod)) {
                throw new InvalidArgumentException(
                    "حقوق «{$party->name}» برای دوره {$jalaliPeriod} قبلاً ثبت شده است."
                );
            }

            $net = $gross - $advance;

            $prepared[] = [
                'employee_id' => $employeeId,
                'party_id' => $party->id,
                'gross' => $gross,
                'advances_deducted' => $advance,
                'net' => $net,
                'immediate_payment' => $this->prepareImmediatePayment($item['immediate_payment'] ?? null, $party->name, $net),
            ];
        }

        return $prepared;
    }

    /**
     * «پرداخت هم‌زمان» — every field the section asks for is required the moment
     * it is enabled at all, with no partial submission accepted: a payment row
     * with an amount but no bank account is not a smaller payment, it is a
     * payment that cannot be posted, and it must fail here rather than reach
     * PaymentRecorder half-formed.
     *
     * Capped at this employee's NET for THIS run, not their total payable — «پرداخت
     * هم‌زمان» pays down what was just earned, full or partial; settling an OLDER
     * outstanding balance at the same time is the standalone «پرداخت حقوق» form's
     * job, which is capped against the party's true total instead.
     *
     * @return array{amount: int, bank_account_id: int, accounting_date: string, method: string, reference: string, note: string}|null
     */
    private function prepareImmediatePayment(?array $payment, string $partyName, int $net): ?array
    {
        if ($payment === null || $payment === []) {
            return null;
        }

        $amount = (int) ($payment['amount'] ?? 0);
        $bankAccountId = (int) ($payment['bank_account_id'] ?? 0);
        $accountingDate = trim((string) ($payment['accounting_date'] ?? ''));
        $method = trim((string) ($payment['method'] ?? ''));
        $reference = trim((string) ($payment['reference'] ?? ''));
        $note = trim((string) ($payment['note'] ?? ''));

        if ($amount < 1 || $bankAccountId < 1 || $accountingDate === '' || $method === '' || $reference === '' || $note === '') {
            throw new InvalidArgumentException(
                "برای «پرداخت هم‌زمان» «{$partyName}»، مبلغ پرداخت، حساب پرداخت‌کننده، تاریخ پرداخت، "
                .'روش پرداخت، شماره پیگیری و توضیحات همگی الزامی‌اند.'
            );
        }

        if (! array_key_exists($method, PartyPayment::METHODS)) {
            throw new InvalidArgumentException("روش پرداخت نامعتبر برای «{$partyName}».");
        }

        if ($net <= 0) {
            throw new InvalidArgumentException(
                "چیزی از حقوق «{$partyName}» در این دوره تحقق نیافته تا هم‌زمان پرداخت شود."
            );
        }

        if ($amount > $net) {
            throw new InvalidArgumentException(
                "مبلغ «پرداخت هم‌زمان» «{$partyName}» بیشتر از خالص همین دوره است: حداکثر ".number_format($net).' تومان.'
            );
        }

        return [
            'amount' => $amount,
            'bank_account_id' => $bankAccountId,
            'accounting_date' => $accountingDate,
            'method' => $method,
            'reference' => $reference,
            'note' => $note,
        ];
    }

    /**
     * The payable this run created, as it stands now. If any of it has been paid,
     * the run's own entry is no longer the whole story and cannot simply be undone.
     */
    private function assertNoSalaryPaid(PayrollRun $run): void
    {
        $run->loadMissing('items.employee.party');

        foreach ($run->items as $item) {
            $party = $item->employee?->party;

            if (! $party) {
                continue;
            }

            if ($this->ledger->paidSalary($party) > 0) {
                throw new OperationStateException(
                    "«{$party->name}» بخشی از حقوق خود را دریافت کرده است. ابتدا پرداخت‌های حقوق را برگشت بزنید، سپس این لیست را."
                );
            }
        }
    }
}
