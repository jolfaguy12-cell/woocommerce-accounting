<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use Illuminate\Console\Command;

class MergeDuplicateCustomerPartiesCommand extends Command
{
    protected $signature = 'acc:customers:merge-duplicates
        {--dry-run : Only report how many parties/orders would be merged, do not change anything}
        {--json : Machine-readable output}';

    protected $description = 'One-off/repair: before CustomerResolver deduped guest checkouts by name, every phone-less order became its own party. Merge those same-name duplicates into one canonical party per name — reassigns their orders and, for any order with an already-posted profit journal entry, reverses + reposts it so the AR party_id is correct too. Never deletes a party row.';

    public function handle(ProfitEngine $engine): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $names = Party::where('type', 'customer')
            ->whereNull('phone')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        $stats = ['groups' => 0, 'parties_merged' => 0, 'orders_moved' => 0, 'journal_entries_reposted' => 0];

        foreach ($names as $name) {
            $parties = Party::where('type', 'customer')->whereNull('phone')->where('name', $name)
                ->orderBy('id')->get();

            $canonical = $parties->first();
            $duplicateIds = $parties->slice(1)->pluck('id');

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            $stats['groups']++;
            $stats['parties_merged'] += $duplicateIds->count();

            $orders = Order::whereIn('customer_party_id', $duplicateIds)->with('profit.journalEntry')->get();
            $stats['orders_moved'] += $orders->count();

            if ($dryRun) {
                continue;
            }

            foreach ($orders as $order) {
                $order->update(['customer_party_id' => $canonical->id]);

                $hasPostedEntry = $order->profit && $order->profit->journal_entry_id
                    && $order->profit->journalEntry?->status === 'posted';

                if ($hasPostedEntry) {
                    $engine->recalculate($order->fresh(), force: true);
                    $stats['journal_entries_reposted']++;
                }
            }
        }

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info(($dryRun ? '[dry-run] ' : '')."Merged {$stats['parties_merged']} duplicate parties across {$stats['groups']} names — moved {$stats['orders_moved']} orders, reposted {$stats['journal_entries_reposted']} journal entries.");

        return self::SUCCESS;
    }
}
