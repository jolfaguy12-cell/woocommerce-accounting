<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * An expense has two halves, and only one of them was ever a choice.
 *
 * The DEBIT is what was spent on: the category's expense account, or fixed
 * assets when the spend is capital. That part was always right.
 *
 * The CREDIT is who paid, and it used to be hard-wired to a company bank
 * account — the only thing an expense could possibly be. So an unpaid invoice
 * and an expense an employee covered from their own pocket were both booked as
 * company cash leaving the bank: the balance dropped although no company money
 * moved, and the debt the company had just taken on (to the supplier, or to the
 * employee) was never recorded anywhere at all.
 *
 * `ExpenseFundingSource` makes the credit side explicit. Everything still posts
 * through JournalPoster, as one balanced entry, keyed on the expense uuid.
 */
class ExpenseRecorder
{
    public function __construct(private readonly JournalPoster $poster) {}

    public function record(array $data): Expense
    {
        return DB::transaction(function () use ($data) {
            $date = $data['expense_date'] instanceof Carbon
                ? $data['expense_date']
                : Carbon::parse($data['expense_date'], JalaliPeriod::TIMEZONE);

            $costCenterId = $data['cost_center_id']
                ?? (isset($data['cost_center_slug'])
                    ? CostCenter::where('slug', $data['cost_center_slug'])->firstOrFail()->id
                    : null);

            $source = ExpenseFundingSource::from($data['funding_source'] ?? ExpenseFundingSource::Bank->value);

            $this->assertFundable($source, $data);

            $expense = Expense::create([
                'uuid' => (string) Str::uuid(),
                'expense_category_id' => $data['expense_category_id'],
                'cost_center_id' => $costCenterId,
                'party_id' => $data['party_id'] ?? null,
                'bank_account_id' => $source->needsBankAccount() ? $data['bank_account_id'] : null,
                'funding_source' => $source->value,
                'funded_by_party_id' => $source->needsParty() ? $data['funded_by_party_id'] : null,
                'amount' => (int) $data['amount'],
                'expense_date' => $date->toDateString(),
                'jalali_period' => JalaliPeriod::fromDate($date),
                'description' => $data['description'],
                'affects_partner_profit' => $data['affects_partner_profit'] ?? true,
                'is_capital' => $data['is_capital'] ?? false,
                'created_by' => $data['created_by'] ?? null,
            ]);

            return $this->postJournal($expense);
        });
    }

    /** Post (or re-attach) the journal entry for an expense; idempotent per expense uuid. */
    public function postJournal(Expense $expense): Expense
    {
        $entry = $this->poster->post([
            'entry_date' => Carbon::parse($expense->expense_date, JalaliPeriod::TIMEZONE),
            'description' => "هزینه: {$expense->description}",
            'idempotency_key' => "expense:{$expense->uuid}",
            'source' => $expense,
            'created_by' => $expense->created_by,
        ], [
            [
                'account' => $expense->is_capital
                    ? AccountCode::FixedAssets
                    : $expense->category->account_code,
                'debit' => $expense->amount,
                'cost_center_id' => $expense->cost_center_id,
                // Who the expense is ABOUT (the vendor it was spent with), which is
                // not necessarily who paid for it.
                'party_id' => $expense->party_id,
            ],
            $this->creditLine($expense),
        ]);

        $expense->forceFill(['journal_entry_id' => $entry->id])->save();

        return $expense->load('journalEntry.lines');
    }

    /**
     * The credit half — the whole point of the funding source.
     *
     * Note the party_id on every non-bank line: without it the credit lands on
     * the right account but belongs to nobody, and «مانده حقوق» / «هزینه
     * پرداخت‌شده توسط کارمند» would show zero for the person actually owed.
     */
    private function creditLine(Expense $expense): array
    {
        $source = $expense->fundingSource();

        if ($source === ExpenseFundingSource::Bank) {
            return [
                'account' => $expense->bankAccount->account_id,
                'credit' => $expense->amount,
            ];
        }

        return [
            'account' => $source->creditAccount(),
            'credit' => $expense->amount,
            'party_id' => $expense->funded_by_party_id,
        ];
    }

    /**
     * A funding source that cannot name its own counterparty is how a plug gets
     * into the ledger. Refuse it at the door rather than posting to an account
     * that nobody can be held to.
     */
    private function assertFundable(ExpenseFundingSource $source, array $data): void
    {
        if ($source->needsBankAccount() && blank($data['bank_account_id'] ?? null)) {
            throw new InvalidArgumentException('برای هزینه پرداخت‌شده از حساب شرکت، انتخاب حساب بانکی یا صندوق الزامی است.');
        }

        if (! $source->needsParty()) {
            return;
        }

        $partyId = $data['funded_by_party_id'] ?? null;

        if (blank($partyId)) {
            throw new InvalidArgumentException('برای این نوع هزینه باید مشخص شود بدهی به کدام طرف حساب ثبت می‌شود.');
        }

        $role = $source->requiredRole();

        if ($role && ! Party::findOrFail($partyId)->hasRole($role)) {
            throw new InvalidArgumentException("طرف حساب انتخاب‌شده نقش «{$role->label()}» ندارد.");
        }
    }
}
