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
 * The two halves are separate on purpose, because they are separate events:
 *
 *   ACCRUAL («ثبت حقوق دوره») — the salary is EARNED. Dr salary expense (6100),
 *   Cr payroll payable (2300) per employee. No cash moves; the company now owes
 *   the money. This is what makes a month's profit include the month's salaries
 *   even when payday falls in the next month.
 *
 *   PAYMENT («پرداخت حقوق») — the money is HANDED OVER. Dr that employee's payroll
 *   payable, Cr the bank. The expense is not touched: it was recognised when the
 *   salary was earned, and recognising it again here would double every salary in
 *   the books while balancing perfectly.
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
     * «ثبت حقوق دوره» — accrue one period's salaries.
     *
     * The payable and advance lines are per EMPLOYEE and carry their party_id. They
     * used to be single aggregate lines with no party at all, which balanced
     * perfectly and told you nothing: the company's total salary debt was right,
     * while every individual employee's «مانده حقوق» read zero, because not one
     * journal line said whose salary it was. An unattributable balance is not a
     * balance — you cannot pay it, dispute it, or reconcile it.
     *
     * @param  array<int, array{employee_id: int, gross: int, advances_deducted?: int}>  $items
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
                'created_by' => $by,
            ], $lines);

            $run->update([
                'status' => PayrollRun::POSTED,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ]);

            return $run->load('journalEntry.lines', 'items');
        });
    }

    /**
     * «پرداخت حقوق» — hand the money over. Capped at what this employee is actually
     * owed (PaymentRecorder::paySalary), because paying more salary than was earned
     * does not clear a debt, it inverts one: 2300 goes negative and reads as the
     * employee owing the company salary, which is not a thing that can be true. An
     * overpayment is a real event — but it is an advance, and it has its own button.
     */
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

        if (! $party->hasRole('employee')) {
            throw new InvalidArgumentException("«{$party->name}» نقش کارمند ندارد.");
        }

        return $this->payments->paySalary($party, $amount, $bankAccountId, $accountingDate, $reference, $note, $by);
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
     * way to tell which.
     *
     * @return array<int, array{employee_id: int, party_id: int, gross: int, advances_deducted: int, net: int}>
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

            $prepared[] = [
                'employee_id' => $employeeId,
                'party_id' => $party->id,
                'gross' => $gross,
                'advances_deducted' => $advance,
                'net' => $gross - $advance,
            ];
        }

        return $prepared;
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
