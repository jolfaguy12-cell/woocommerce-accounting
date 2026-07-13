<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Orders\Models\Order;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\PartyPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MergeDuplicateCustomerPartiesCommand extends Command
{
    protected $signature = 'acc:customers:merge-duplicates
        {--dry-run : Only report what would be merged, do not change anything}
        {--json : Machine-readable output}';

    protected $description = 'One-off/repair: before CustomerResolver deduped guest checkouts by name, every phone-less order became its own party. Merges those same-name duplicates into one canonical party per name by reassigning their orders. REFUSES any party carrying financial history — Party merge is deferred until an auditable merge flow exists.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $names = Party::withRole(PartyRoleType::Customer)
            ->whereNull('phone')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        $stats = ['groups' => 0, 'parties_merged' => 0, 'orders_moved' => 0, 'refused_groups' => 0, 'refused_parties' => 0];
        $refusals = [];

        foreach ($names as $name) {
            $parties = Party::withRole(PartyRoleType::Customer)->whereNull('phone')->where('name', $name)
                ->orderBy('id')->get();

            $canonical = $parties->first();
            $duplicates = $parties->slice(1);

            if ($duplicates->isEmpty()) {
                continue;
            }

            // A party with financial history cannot be merged here. Reassigning
            // its records would mean rewriting journal_lines.party_id, and journal
            // lines are immutable. The previous version of this command worked
            // around that by reversing and reposting each order's profit entry —
            // a real ledger mutation, performed by a repair script, with no
            // approval, no reason recorded and no reversal trail of its own. That
            // is precisely the merge this project deferred, so it is refused
            // rather than quietly performed.
            $unsafe = $duplicates->filter(fn (Party $party) => $this->financialHistory($party)->isNotEmpty());

            if ($unsafe->isNotEmpty()) {
                $stats['refused_groups']++;
                $stats['refused_parties'] += $unsafe->count();

                foreach ($unsafe as $party) {
                    $refusals[] = [
                        'party_id' => $party->id,
                        'name' => $party->name,
                        'history' => $this->financialHistory($party)->all(),
                    ];
                }

                continue;
            }

            $orders = Order::whereIn('customer_party_id', $duplicates->pluck('id'))->get();

            $stats['groups']++;
            $stats['parties_merged'] += $duplicates->count();
            $stats['orders_moved'] += $orders->count();

            if ($dryRun) {
                continue;
            }

            // Safe by construction: none of these orders has a posted profit entry
            // (financialHistory() would have caught it), so moving one touches no
            // journal line. No party row is ever deleted.
            foreach ($orders as $order) {
                $order->update(['customer_party_id' => $canonical->id]);
            }
        }

        $this->report($stats, $refusals, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Every kind of financial record that ties a party to the ledger. Any one of
     * them makes the party unmergeable by this command.
     *
     * @return Collection<int, string>
     */
    private function financialHistory(Party $party): Collection
    {
        $checks = [
            'journal_lines' => JournalLine::where('party_id', $party->id)->exists(),
            'party_payments' => PartyPayment::where('party_id', $party->id)->exists(),
            'credit_orders' => CreditOrder::where('party_id', $party->id)->exists(),
            'purchase_invoices' => PurchaseInvoice::where('supplier_party_id', $party->id)->exists(),
            'loans' => Loan::where('party_id', $party->id)->exists(),
            'cheques' => Cheque::where('party_id', $party->id)->exists(),
            'expenses' => Expense::where('party_id', $party->id)->exists(),
            // An order whose profit is posted carries an AR journal line under
            // this party_id — moving the order would orphan it from its party.
            'posted_order_profit' => Order::where('customer_party_id', $party->id)
                ->whereHas('profit', fn ($q) => $q->whereNotNull('journal_entry_id'))
                ->exists(),
        ];

        return collect($checks)->filter()->keys();
    }

    private function report(array $stats, array $refusals, bool $dryRun): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($stats + ['refusals' => $refusals], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info($prefix."Merged {$stats['parties_merged']} duplicate parties across {$stats['groups']} names — moved {$stats['orders_moved']} orders.");

        if ($stats['refused_parties'] > 0) {
            $this->warn("Refused {$stats['refused_parties']} party/parties across {$stats['refused_groups']} name group(s): they carry financial history, and merging them would rewrite immutable journal lines.");

            foreach ($refusals as $refusal) {
                $this->line("  · #{$refusal['party_id']} {$refusal['name']} — ".implode(', ', $refusal['history']));
            }

            $this->line('These need the auditable Party merge flow, which is not built yet.');
        }
    }
}
