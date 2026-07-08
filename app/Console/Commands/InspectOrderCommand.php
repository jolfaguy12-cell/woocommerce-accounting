<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use Illuminate\Console\Command;

class InspectOrderCommand extends Command
{
    protected $signature = 'acc:inspect:order {hub_order_id} {--json : Machine-readable output}';

    protected $description = 'Explain one order: normalization, channel, validity, and full profit breakdown';

    public function handle(): int
    {
        $order = Order::with('items', 'channel', 'profit.journalEntry')
            ->firstWhere('hub_order_id', (int) $this->argument('hub_order_id'));

        if (! $order) {
            $this->error('Order not found locally (sync it first with acc:sync:order).');

            return self::FAILURE;
        }

        $result = [
            'hub_order_id' => $order->hub_order_id,
            'status' => $order->status,
            'financial_state' => $order->financial_state,
            'profit_status' => $order->profit_status,
            'jalali_period' => $order->jalali_period,
            'channel' => $order->channel?->slug,
            'raw_source' => $order->raw_source_value,
            'totals' => [
                'total' => $order->total,
                'discount' => $order->discount_total,
                'shipping_charged' => $order->shipping_charged,
            ],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name, 'qty' => $i->qty, 'line_total' => $i->line_total,
                'hub_product_id' => $i->hub_product_id, 'mapped' => $i->product_mirror_id !== null,
            ])->all(),
            'profit' => $order->profit ? [
                'version' => $order->profit->version,
                'status' => $order->profit->status,
                'gross_sale' => $order->profit->gross_sale,
                'discounts' => $order->profit->discounts,
                'net_sale' => $order->profit->net_sale,
                'product_cost' => $order->profit->product_cost,
                'cost_breakdown' => $order->profit->cost_breakdown,
                'shipping_charged' => $order->profit->shipping_charged,
                'shipping_real' => $order->profit->shipping_real,
                'shipping_basis' => $order->profit->shipping_basis,
                'channel_fee' => $order->profit->channel_fee,
                'gross_profit' => $order->profit->gross_profit,
                'operational_profit' => $order->profit->operational_profit,
                'journal_entry' => $order->profit->journalEntry?->uuid,
            ] : null,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->info("سفارش {$order->hub_order_id} — {$order->status} ({$order->financial_state}) / کانال: ".($result['channel'] ?? 'نامشخص'));
            $this->line('سود عملیاتی: '.number_format($result['profit']['operational_profit'] ?? 0).' تومان (وضعیت: '.($result['profit']['status'] ?? '—').')');
            foreach ($result['items'] as $item) {
                $this->line("  - {$item['name']} ×{$item['qty']} = ".number_format($item['line_total']).($item['mapped'] ? '' : '  ⚠ بدون نگاشت'));
            }
        }

        return self::SUCCESS;
    }
}
