<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Moves historical supplier overpayments off the payable account and onto 1450.
 *
 * Before the advance split shipped, paying a supplier more than we owed simply
 * drove their payable NEGATIVE — a prepayment recorded as a negative liability.
 * New payments no longer do that, but every overpayment already in the ledger
 * still reads that way, so the two conventions would coexist forever and no
 * report could trust either.
 *
 * This POSTS the correction; it never edits history. One balanced entry per
 * affected supplier (debit 1450, credit 2000) leaves the original entries exactly
 * as they were and adds the reclassification on top, where it can be seen,
 * explained and reversed. Total assets and total liabilities are both restated;
 * the NET position of every supplier is unchanged, which is what makes it a
 * reclassification and not an adjustment.
 *
 * Idempotent through the key `supplier_advance_reclass:{party_id}` — running it
 * twice posts nothing the second time.
 */
class ReclassSupplierAdvancesCommand extends Command
{
    protected $signature = 'suppliers:reclass-advances
        {--dry-run : Report what would be posted, change nothing}
        {--json : Machine-readable output}';

    protected $description = 'One-off: reclassify historical supplier overpayments from a negative payable (2000) onto the supplier-advance account (1450). Posts a balanced correction per supplier; never edits an existing entry.';

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly PartyLedgerService $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['suppliers' => 0, 'reclassified' => 0, 'already_done' => 0, 'posted' => 0];
        $rows = [];

        foreach ($this->overpaidSuppliers() as $party) {
            $payable = $this->ledger->supplierPayable($party);

            // A negative payable IS the historical advance. Anything else is a
            // normal balance and none of this command's business.
            if ($payable >= 0) {
                continue;
            }

            $amount = -$payable;
            $stats['suppliers']++;

            $before = [
                'payable' => $payable,
                'advance' => $this->ledger->balanceOn($party, AccountCode::SupplierAdvance),
            ];

            $rows[] = [
                'party_id' => $party->id,
                'name' => $party->name,
                'amount' => $amount,
                'payable_before' => $before['payable'],
                'advance_before' => $before['advance'],
            ];

            if ($dryRun) {
                $stats['reclassified'] += $amount;

                continue;
            }

            $key = "supplier_advance_reclass:{$party->id}";
            $existing = JournalEntry::firstWhere('idempotency_key', $key);

            if ($existing) {
                $stats['already_done']++;

                continue;
            }

            $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "طبقه‌بندی مجدد پیش‌پرداخت تأمین‌کننده {$party->name}",
                'idempotency_key' => $key,
            ], [
                [
                    'account' => AccountCode::SupplierAdvance,
                    'debit' => $amount,
                    'party_id' => $party->id,
                    'memo' => 'انتقال مانده بدهکار حساب پرداختنی به پیش‌پرداخت',
                ],
                [
                    'account' => AccountCode::AccountsPayable,
                    'credit' => $amount,
                    'party_id' => $party->id,
                    'memo' => 'انتقال مانده بدهکار به حساب پیش‌پرداخت',
                ],
            ]);

            $stats['posted']++;
            $stats['reclassified'] += $amount;

            // Proof, not assumption: after the entry the payable must be flat and the
            // advance must hold exactly what the payable was carrying.
            $party->refresh();
            $rows[count($rows) - 1]['payable_after'] = $this->ledger->supplierPayable($party);
            $rows[count($rows) - 1]['advance_after'] = $this->ledger->balanceOn($party, AccountCode::SupplierAdvance);
        }

        $this->report($stats, $rows, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Only parties that actually have payable activity — there is no point asking
     * the ledger about every customer in the database.
     *
     * @return Collection<int, Party>
     */
    private function overpaidSuppliers()
    {
        $partyIds = JournalLine::where('account_id', AccountCode::AccountsPayable->account()->id)
            ->whereNotNull('party_id')
            ->distinct()
            ->pluck('party_id');

        return Party::whereIn('id', $partyIds)->orderBy('id')->get();
    }

    private function report(array $stats, array $rows, bool $dryRun): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($stats + ['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';

        if ($stats['suppliers'] === 0) {
            $this->info($prefix.'No supplier carries a negative payable — nothing to reclassify.');

            return;
        }

        $this->table(
            ['#', 'تأمین‌کننده', 'مبلغ', 'پرداختنی (قبل)', 'پیش‌پرداخت (قبل)'],
            array_map(fn ($r) => [
                $r['party_id'], $r['name'], number_format($r['amount']),
                number_format($r['payable_before']), number_format($r['advance_before']),
            ], $rows),
        );

        $this->info($prefix.'Reclassified '.number_format($stats['reclassified'])." Toman across {$stats['suppliers']} supplier(s); "
            ."{$stats['posted']} entry/entries posted, {$stats['already_done']} already done.");
    }
}
