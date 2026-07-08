<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Expenses\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseRecorder
{
    private const FIXED_ASSET_ACCOUNT = '1500';

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

            $expense = Expense::create([
                'uuid' => (string) Str::uuid(),
                'expense_category_id' => $data['expense_category_id'],
                'cost_center_id' => $costCenterId,
                'party_id' => $data['party_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'],
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
        $debitAccount = $expense->is_capital
            ? self::FIXED_ASSET_ACCOUNT
            : $expense->category->account_code;

        $entry = $this->poster->post([
            'entry_date' => Carbon::parse($expense->expense_date, JalaliPeriod::TIMEZONE),
            'description' => "هزینه: {$expense->description}",
            'idempotency_key' => "expense:{$expense->uuid}",
            'source' => $expense,
            'created_by' => $expense->created_by,
        ], [
            [
                'account' => $debitAccount,
                'debit' => $expense->amount,
                'cost_center_id' => $expense->cost_center_id,
                'party_id' => $expense->party_id,
            ],
            [
                'account' => $expense->bankAccount->account_id,
                'credit' => $expense->amount,
            ],
        ]);

        $expense->forceFill(['journal_entry_id' => $entry->id])->save();

        return $expense->load('journalEntry.lines');
    }
}
