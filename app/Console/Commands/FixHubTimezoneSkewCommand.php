<?php

namespace App\Console\Commands;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\RawOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixHubTimezoneSkewCommand extends Command
{
    protected $signature = 'acc:fix-hub-timezone-skew
        {--dry-run : Only report how many rows would change, do not update}
        {--json : Machine-readable output}';

    protected $description = 'One-off: correct order_date/date_paid/hub_modified_at written before the APP_TIMEZONE mislabeling fix (see JalaliPeriod::parseHubGmt) by a deterministic +03:30 shift, and recompute jalali_period where the corrected date crosses into a different Jalali day';

    /** Tehran is a fixed +03:30 offset year-round (no DST since 2022) — safe as a flat constant shift. */
    private const SKEW_MINUTES = 210;

    public function handle(): int
    {
        $stats = [
            'orders_order_date' => Order::count(),
            'orders_date_paid' => Order::whereNotNull('date_paid')->count(),
            'product_mirror_hub_modified_at' => ProductMirror::whereNotNull('hub_modified_at')->count(),
            'raw_orders_hub_modified_at' => RawOrder::whereNotNull('hub_modified_at')->count(),
        ];

        if ($this->option('dry-run')) {
            $this->info("Would shift {$stats['orders_order_date']} order_date, {$stats['orders_date_paid']} date_paid, {$stats['product_mirror_hub_modified_at']} product hub_modified_at, {$stats['raw_orders_hub_modified_at']} raw_order hub_modified_at by +03:30, and recompute jalali_period.");
            $this->option('json') && $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $periodChanges = [];

        DB::transaction(function () use (&$periodChanges) {
            Order::select('id', 'hub_order_id', 'order_date', 'date_paid', 'jalali_period')
                ->chunkById(500, function ($orders) use (&$periodChanges) {
                    foreach ($orders as $order) {
                        $newOrderDate = $order->order_date->copy()->addMinutes(self::SKEW_MINUTES);
                        $newDatePaid = $order->date_paid?->copy()->addMinutes(self::SKEW_MINUTES);
                        $newPeriod = JalaliPeriod::fromDate($newOrderDate);

                        if ($newPeriod !== $order->jalali_period) {
                            $periodChanges[] = ['hub_order_id' => $order->hub_order_id, 'from' => $order->jalali_period, 'to' => $newPeriod];
                        }

                        $order->update(['order_date' => $newOrderDate, 'date_paid' => $newDatePaid, 'jalali_period' => $newPeriod]);
                    }
                });

            ProductMirror::whereNotNull('hub_modified_at')->select('id', 'hub_modified_at')
                ->chunkById(500, fn ($rows) => $rows->each(
                    fn ($row) => $row->update(['hub_modified_at' => $row->hub_modified_at->copy()->addMinutes(self::SKEW_MINUTES)])
                ));

            RawOrder::whereNotNull('hub_modified_at')->select('id', 'hub_modified_at')
                ->chunkById(500, fn ($rows) => $rows->each(
                    fn ($row) => $row->update(['hub_modified_at' => $row->hub_modified_at->copy()->addMinutes(self::SKEW_MINUTES)])
                ));
        });

        $stats['jalali_period_changed'] = count($periodChanges);
        $stats['jalali_period_changes'] = $periodChanges;

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Shifted {$stats['orders_order_date']} order_date, {$stats['orders_date_paid']} date_paid, {$stats['product_mirror_hub_modified_at']} product hub_modified_at, {$stats['raw_orders_hub_modified_at']} raw_order hub_modified_at by +03:30.");
            $this->info("{$stats['jalali_period_changed']} order(s) crossed into a different Jalali period.");
            foreach ($periodChanges as $c) {
                $this->line("  order {$c['hub_order_id']}: {$c['from']} -> {$c['to']}");
            }
        }

        return self::SUCCESS;
    }
}
