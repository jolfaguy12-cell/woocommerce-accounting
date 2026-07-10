<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\OrderProfit;
use App\Domain\Orders\Services\ProfitEngine;
use Illuminate\Console\Command;

class BackfillPackagingCostCommand extends Command
{
    protected $signature = 'acc:orders:backfill-packaging-cost
        {--json : Machine-readable output}';

    protected $description = 'One-off: resolve packaging_cost for order_profits rows calculated before the packaging-cost feature existed — updates only the tracking fields, never touches the journal, inputs_hash, or status';

    public function handle(ProfitEngine $engine): int
    {
        $profiles = OrderProfit::whereNull('packaging_cost')->with('order.items.productMirror', 'order.packagingCost')->get();

        $stats = ['total' => $profiles->count(), 'updated' => 0];
        $bar = $this->output->createProgressBar($stats['total']);
        $bar->start();

        foreach ($profiles as $profile) {
            if ($profile->order) {
                $profile->update($engine->resolvePackagingSnapshot($profile->order));
                $stats['updated']++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->option('json')
            ? $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES))
            : $this->info("Backfilled packaging cost for {$stats['updated']} of {$stats['total']} order_profits rows.");

        return self::SUCCESS;
    }
}
