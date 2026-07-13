<?php

namespace App\Console\Commands;

use App\Domain\Receivables\Services\LoanService;
use Illuminate\Console\Command;

/**
 * Mark loans and installments overdue — and un-mark them when they are not.
 *
 * This touches STATUS COLUMNS ONLY and posts nothing, which is the whole point. An
 * installment falling due changes nothing in the accounts: the money was already owed
 * from the day the loan was made, and being late about it does not make us owe more.
 * Any "overdue" process that posts an entry has invented a liability.
 */
class RefreshOverdueLoansCommand extends Command
{
    protected $signature = 'loans:refresh-overdue {--json}';

    protected $description = 'Flag overdue loan installments (status only — never touches the ledger)';

    public function handle(LoanService $loans): int
    {
        $result = $loans->refreshOverdue();

        if ($this->option('json')) {
            $this->line(json_encode($result));

            return self::SUCCESS;
        }

        $this->info("اقساط معوق: {$result['installments']} — وام‌های معوق: {$result['loans']}");

        return self::SUCCESS;
    }
}
