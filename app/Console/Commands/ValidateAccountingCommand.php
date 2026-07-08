<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ValidateAccountingCommand extends Command
{
    protected $signature = 'acc:validate {--json : Machine-readable output}';

    protected $description = 'Validate ledger integrity: trial balance, per-entry balance, orphan/invalid lines';

    public function handle(): int
    {
        $trialDebit = (int) DB::table('journal_lines')->sum('debit');
        $trialCredit = (int) DB::table('journal_lines')->sum('credit');

        $unbalancedEntries = DB::table('journal_lines')
            ->select('journal_entry_id')
            ->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit) != SUM(credit)')
            ->count();

        $doubleSidedLines = DB::table('journal_lines')
            ->where('debit', '>', 0)->where('credit', '>', 0)->count();

        $orphanLines = DB::table('journal_lines')
            ->leftJoin('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereNull('journal_entries.id')->count();

        $result = [
            'ok' => $trialDebit === $trialCredit && $unbalancedEntries === 0 && $doubleSidedLines === 0 && $orphanLines === 0,
            'trial_balance' => ['debits' => $trialDebit, 'credits' => $trialCredit, 'difference' => $trialDebit - $trialCredit],
            'unbalanced_entries' => $unbalancedEntries,
            'double_sided_lines' => $doubleSidedLines,
            'orphan_lines' => $orphanLines,
            'entries' => (int) DB::table('journal_entries')->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($result['ok'] ? '✔' : '✘')." trial balance: {$trialDebit} / {$trialCredit}");
            $this->line("  unbalanced entries: {$unbalancedEntries}, double-sided lines: {$doubleSidedLines}, orphan lines: {$orphanLines}");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
