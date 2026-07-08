<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderProfit;
use App\Domain\Sync\Models\ReviewItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-order profit: validity gate → costing → shipping → channel fee →
 * balanced journal. Missing data blocks posting (never zero) and opens
 * review items. Recalculation reverses and reposts a new version.
 */
class ProfitEngine
{
    private const AR = '1200';

    private const REVENUE = '4000';

    private const SHIPPING_INCOME = '4100';

    private const COGS = '5000';

    private const INVENTORY = '1300';

    private const SHIPPING_EXPENSE = '5100';

    private const PAYABLES = '2000';

    private const CHANNEL_FEE = '5200';

    public function __construct(
        private readonly CostResolver $costs,
        private readonly JournalPoster $poster,
    ) {}

    /** Entry point after every (re)normalization. */
    public function evaluate(Order $order): void
    {
        if (in_array($order->financial_state, ['cancelled', 'refunded', 'void'], true)
            || ! $this->isFinanciallyValid($order)) {
            $this->reverseIfPosted($order, "وضعیت سفارش از حالت معتبر خارج شد ({$order->status})");

            return;
        }

        if ($order->financial_state !== 'partially_refunded') {
            $order->update(['financial_state' => 'valid']);
        }

        $this->recalculate($order);
    }

    public function recalculate(Order $order): ?OrderProfit
    {
        return DB::transaction(function () use ($order) {
            $order->loadMissing('items.productMirror', 'channel');
            $result = $this->calculate($order);
            $hash = hash('sha256', json_encode($result));

            $existing = OrderProfit::firstWhere('order_id', $order->id);

            if ($existing && $existing->inputs_hash === $hash && $existing->status !== 'reversed') {
                return $existing; // nothing changed
            }

            $version = $existing?->version ?? 1;
            if ($existing?->journal_entry_id && $existing->journalEntry?->status === 'posted') {
                $this->poster->reverse($existing->journalEntry, 'بازمحاسبه سود سفارش', null);
                $version++;
            }

            if ($result['blocked']) {
                $profit = $this->storeProfit($order, $result, $version, 'blocked', null, $hash);
                $order->update(['profit_status' => 'blocked_missing_cost']);
                $this->openOnce('missing_cost', $order, ['missing' => $result['missing']]);

                return $profit;
            }

            $status = $result['warnings'] === [] ? 'final' : 'provisional';
            $entry = $this->postJournal($order, $result, $version);
            $profit = $this->storeProfit($order, $result, $version, $status, $entry?->id, $hash);

            $order->update(['profit_status' => $status === 'final' ? 'ok' : 'needs_review']);

            foreach ($result['warnings'] as $warning) {
                $this->openOnce($warning, $order, []);
            }

            return $profit;
        });
    }

    private function isFinanciallyValid(Order $order): bool
    {
        $validStatuses = $order->channel?->valid_statuses
            ?: Setting::get('default_valid_statuses', ['completed']);

        return in_array($order->status, $validStatuses, true);
    }

    private function calculate(Order $order): array
    {
        $gross = (int) $order->items->sum('line_subtotal');
        $net = (int) $order->items->sum('line_total');

        [$cost, $breakdown, $missing] = $this->productCost($order);
        [$shippingReal, $shippingBasis] = $this->shipping($order);
        [$fee, $feeSource, $warnings] = $this->channelFee($order);

        return [
            'blocked' => $missing !== [],
            'missing' => $missing,
            'warnings' => $warnings,
            'gross_sale' => $gross,
            'discounts' => $gross - $net,
            'net_sale' => $net,
            'product_cost' => $missing === [] ? $cost : null,
            'cost_breakdown' => $breakdown,
            'shipping_charged' => $order->shipping_charged,
            'shipping_real' => $shippingReal,
            'shipping_basis' => $shippingBasis,
            'channel_fee' => $fee,
            'channel_fee_source' => $feeSource,
            'gateway_fee' => 0, // not exposed by the hub yet
            'gross_profit' => $missing === [] ? $net - $cost : null,
            'operational_profit' => $missing === []
                ? $net - $cost + $order->shipping_charged - $shippingReal - $fee
                : null,
        ];
    }

    private function productCost(Order $order): array
    {
        $total = 0;
        $breakdown = [];
        $missing = [];

        foreach ($order->items as $item) {
            $resolved = $item->productMirror ? $this->costs->resolveFor($item->productMirror) : null;

            if ($resolved === null) {
                $missing[] = ['item' => $item->name, 'hub_product_id' => $item->hub_product_id];

                continue;
            }

            $lineCost = $resolved['unit_cost'] * $item->qty;
            $total += $lineCost;
            $breakdown[] = [
                'item' => $item->name,
                'qty' => $item->qty,
                'cost_item_id' => $resolved['cost_item_id'],
                'unit_cost' => $resolved['unit_cost'],
                'line_cost' => $lineCost,
                'source' => $resolved['source'],
                'cost_history_id' => $resolved['cost_history_id'],
            ];
        }

        return [$total, $breakdown, $missing];
    }

    /** README §13: manual real cost → customer-paid (when charged) → default setting. */
    private function shipping(Order $order): array
    {
        if ($manual = $order->shippingCost) {
            return [$manual->real_cost, 'manual'];
        }
        if ($order->shipping_charged > 0) {
            return [$order->shipping_charged, 'customer_paid'];
        }
        if (($default = (int) Setting::get('default_shipping_cost', 0)) > 0) {
            return [$default, 'default'];
        }

        return [0, 'none'];
    }

    private function channelFee(Order $order): array
    {
        if ($order->channel?->cost_model !== 'order_commission') {
            return [0, 'none', []];
        }

        $metaKey = $order->channel->config['commission_meta_key'] ?? null;
        $raw = $metaKey ? ($order->rawOrder->payload['meta'][$metaKey] ?? null) : null;

        if ($raw === null || $raw === '') {
            return [0, 'none', ['missing_commission']];
        }

        return [(int) abs(round((float) $raw)), 'metadata', []];
    }

    private function postJournal(Order $order, array $r, int $version)
    {
        $lines = [
            ['account' => self::AR, 'debit' => $r['net_sale'] + $r['shipping_charged'], 'party_id' => $order->customer_party_id],
            ['account' => self::REVENUE, 'credit' => $r['net_sale']],
        ];

        if ($r['shipping_charged'] > 0) {
            $lines[] = ['account' => self::SHIPPING_INCOME, 'credit' => $r['shipping_charged']];
        }
        if ($r['product_cost'] > 0) {
            $lines[] = ['account' => self::COGS, 'debit' => $r['product_cost']];
            $lines[] = ['account' => self::INVENTORY, 'credit' => $r['product_cost']];
        }
        if ($r['shipping_real'] > 0) {
            $lines[] = ['account' => self::SHIPPING_EXPENSE, 'debit' => $r['shipping_real']];
            $lines[] = ['account' => self::PAYABLES, 'credit' => $r['shipping_real']];
        }
        if ($r['channel_fee'] > 0) {
            $lines[] = ['account' => self::CHANNEL_FEE, 'debit' => $r['channel_fee']];
            $lines[] = ['account' => self::AR, 'credit' => $r['channel_fee']];
        }

        // Zero-value orders (no items, nothing charged) have nothing to post.
        $lines = array_values(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) + ($l['credit'] ?? 0) > 0));
        if ($lines === []) {
            return null;
        }

        return $this->poster->post([
            'entry_date' => $order->order_date->copy()->setTimezone(JalaliPeriod::TIMEZONE),
            'description' => "سود سفارش {$order->hub_order_id}".($version > 1 ? " (نسخه {$version})" : ''),
            'idempotency_key' => "order:{$order->hub_order_id}:profit:v{$version}",
            'source' => $order,
            'correlation_id' => $order->rawOrder->payload['correlation_id'] ?? null,
        ], $lines);
    }

    private function storeProfit(Order $order, array $r, int $version, string $status, ?int $entryId, string $hash): OrderProfit
    {
        return OrderProfit::updateOrCreate(['order_id' => $order->id], [
            'version' => $version,
            'gross_sale' => $r['gross_sale'],
            'discounts' => $r['discounts'],
            'net_sale' => $r['net_sale'],
            'product_cost' => $r['product_cost'],
            'cost_breakdown' => $r['cost_breakdown'],
            'shipping_charged' => $r['shipping_charged'],
            'shipping_real' => $r['shipping_real'],
            'shipping_basis' => $r['shipping_basis'],
            'channel_fee' => $r['channel_fee'],
            'channel_fee_source' => $r['channel_fee_source'],
            'gateway_fee' => $r['gateway_fee'],
            'gross_profit' => $r['gross_profit'],
            'operational_profit' => $r['operational_profit'],
            'status' => $status,
            'journal_entry_id' => $entryId,
            'inputs_hash' => $hash,
            'calculated_at' => now(),
        ]);
    }

    private function reverseIfPosted(Order $order, string $reason): void
    {
        $profit = OrderProfit::firstWhere('order_id', $order->id);

        if ($profit?->journal_entry_id && $profit->journalEntry?->status === 'posted') {
            $this->poster->reverse($profit->journalEntry, $reason, null, Carbon::now(JalaliPeriod::TIMEZONE));
            $profit->update(['status' => 'reversed']);
        }
    }

    private function openOnce(string $type, Order $order, array $payload): void
    {
        $exists = ReviewItem::where('type', $type)
            ->where('subject_type', 'order')
            ->where('subject_id', $order->id)
            ->where('status', 'open')
            ->exists();

        if (! $exists) {
            ReviewItem::open($type, $order, $payload);
        }
    }
}
