<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\ProfitEngine;
use Illuminate\Console\Command;

class RecalcOrderCommand extends Command
{
    protected $signature = 'acc:recalc:order {hub_order_id} {--json : Machine-readable output}';

    protected $description = 'Re-evaluate order validity and profit (reverse + repost when inputs changed)';

    public function handle(ProfitEngine $engine): int
    {
        $order = Order::firstWhere('hub_order_id', (int) $this->argument('hub_order_id'));

        if (! $order) {
            $this->error('Order not found (sync it first with acc:sync:order).');

            return self::FAILURE;
        }

        $engine->evaluate($order);
        $order->refresh()->load('profit');

        $result = [
            'hub_order_id' => $order->hub_order_id,
            'financial_state' => $order->financial_state,
            'profit_status' => $order->profit_status,
            'version' => $order->profit?->version,
            'net_sale' => $order->profit?->net_sale,
            'product_cost' => $order->profit?->product_cost,
            'operational_profit' => $order->profit?->operational_profit,
        ];

        $this->option('json')
            ? $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : $this->info("Order {$order->hub_order_id}: {$order->profit_status} (state: {$order->financial_state}, profit v{$result['version']}: ".($result['operational_profit'] ?? '—').')');

        return self::SUCCESS;
    }
}
