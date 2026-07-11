<?php

namespace App\Domain\Orders\Services;

use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\PackagingCostTier;
use App\Domain\Costing\Services\CostResolver;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderProfit;
use App\Domain\Receivables\Services\CreditOrderSync;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Support\RawOrderMeta;
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
        private readonly CreditOrderSync $creditOrders,
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

    /**
     * $force skips the inputs-unchanged short-circuit — for corrections that don't
     * affect the calculated amounts but do affect the journal (e.g. an order was
     * reassigned to a different customer party after a duplicate-customer merge,
     * so the posted AR line's party_id needs a reversal + repost to stay correct).
     */
    public function recalculate(Order $order, bool $force = false): ?OrderProfit
    {
        return DB::transaction(function () use ($order, $force) {
            $order->loadMissing('items.productMirror', 'channel');
            $result = $this->calculate($order);
            $hash = hash('sha256', json_encode($result));

            $existing = OrderProfit::firstWhere('order_id', $order->id);

            if (! $force && $existing && $existing->inputs_hash === $hash && $existing->status !== 'reversed') {
                // Eligibility for receivables tracking (payment_status, in
                // particular) can change even when nothing financial did —
                // re-check it every time, not just when the amounts change.
                if ($existing->status !== 'blocked') {
                    $this->creditOrders->sync($order, $result, $existing->journal_entry_id);
                }

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
                $this->creditOrders->reverse($order);

                return $profit;
            }

            $status = $result['warnings'] === [] ? 'final' : 'provisional';
            $entry = $this->postJournal($order, $result, $version);
            $profit = $this->storeProfit($order, $result, $version, $status, $entry?->id, $hash);

            $order->update(['profit_status' => $status === 'final' ? 'ok' : 'needs_review']);

            foreach ($result['warnings'] as $warning) {
                $this->openOnce($warning, $order, []);
            }

            $this->creditOrders->sync($order, $result, $entry?->id);

            return $profit;
        });
    }

    /**
     * Resolve packaging cost for an order without touching its stored profit,
     * journal, or inputs_hash — used by one-off backfills to fill the field in
     * for orders calculated before this feature existed.
     */
    public function resolvePackagingSnapshot(Order $order): array
    {
        $order->loadMissing('items.productMirror', 'packagingCost');
        [$cost, $weight, $basis] = $this->packaging($order);

        return ['packaging_cost' => $cost, 'package_weight_grams' => $weight, 'packaging_cost_basis' => $basis];
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
        $lineNet = (int) $order->items->sum('line_total');

        [$cost, $breakdown, $missing] = $this->productCost($order);
        [$shippingReal, $shippingBasis] = $this->shipping($order);
        [$fee, $feeSource, $warnings] = $this->channelFee($order);
        [$marketplaceDiscount, $discountSource] = $this->channelDiscount($order, $gross, $fee, $feeSource);
        [$packagingCost, $packageWeight, $packagingBasis] = $this->packaging($order);

        $discounts = ($gross - $lineNet) + $marketplaceDiscount;
        $net = $lineNet - $marketplaceDiscount;

        return [
            'blocked' => $missing !== [],
            'missing' => $missing,
            'warnings' => $warnings,
            'gross_sale' => $gross,
            'discounts' => $discounts,
            'net_sale' => $net,
            'product_cost' => $missing === [] ? $cost : null,
            'cost_breakdown' => $breakdown,
            'shipping_charged' => $order->shipping_charged,
            'shipping_real' => $shippingReal,
            'shipping_basis' => $shippingBasis,
            'channel_fee' => $fee,
            'channel_fee_source' => $feeSource,
            'channel_discount' => $marketplaceDiscount,
            'channel_discount_source' => $discountSource,
            'gateway_fee' => 0, // not exposed by the hub yet
            // Tracked for visibility only — not folded into gross/operational profit yet.
            'package_weight_grams' => $packageWeight,
            'packaging_cost' => $packagingCost,
            'packaging_cost_basis' => $packagingBasis,
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

    /**
     * README-pending decision: packaging cost is resolved and stored per order
     * for visibility/reporting, same manual-override-then-fallback pattern as
     * shipping(), but is NOT folded into gross/operational profit or posted to
     * the journal yet. Resolved once per calculate() call — later edits to
     * tiers/defaults only affect orders on their next explicit recalculation,
     * never retroactively.
     */
    private function packaging(Order $order): array
    {
        if ($manual = $order->packagingCost) {
            return [$manual->real_cost, null, 'manual'];
        }

        $weight = $this->packageWeight($order);
        $tier = PackagingCostTier::where('min_weight_grams', '<=', $weight)
            ->orderByDesc('min_weight_grams')
            ->first();

        return $tier
            ? [$tier->cost, $weight, 'tier']
            : [(int) Setting::get('default_packaging_cost', 12000), $weight, 'default'];
    }

    /** Sum of item weights (falling back to a configurable default per item when unknown) plus the packaging's own weight. */
    private function packageWeight(Order $order): int
    {
        $defaultItemWeight = (int) Setting::get('default_product_weight_grams', 150);
        $packagingWeight = (int) Setting::get('default_packaging_weight_grams', 100);

        $itemsWeight = $order->items->sum(
            fn ($item) => $item->qty * ($item->productMirror?->weight_grams ?? $defaultItemWeight)
        );

        return (int) $itemsWeight + $packagingWeight;
    }

    private function channelFee(Order $order): array
    {
        if ($order->channel?->cost_model !== 'order_commission') {
            return [0, 'none', []];
        }

        $metaKey = $order->channel->config['commission_meta_key'] ?? null;
        $raw = $metaKey ? RawOrderMeta::get($order->rawOrder->payload, $metaKey) : null;

        // A literal zero is indistinguishable from "Basalam hasn't settled this
        // order yet" — a real settled order's commission is never actually 0.
        // Treat it the same as missing rather than silently understating it.
        if ($raw === null || $raw === '' || (float) $raw === 0.0) {
            return [0, 'none', ['missing_commission']];
        }

        return [(int) abs(round((float) $raw)), 'metadata', []];
    }

    /**
     * Marketplace-level discount (e.g. a Basalam coupon) that never reaches
     * WooCommerce as a line-item discount — the channel's own WooCommerce-sync
     * metadata carries the items total, commission, and final settlement
     * balance, so the gap between them is the discount the marketplace granted
     * on our behalf. Requires both meta keys and never guesses: no balance
     * meta key configured, either value missing, or the commission itself
     * isn't confirmed real yet (see channelFee()) all mean no discount is
     * assumed (same "missing data never treated as zero" rule as cost) —
     * otherwise an unsettled order's unknown commission gets misread as a
     * 100%-off coupon. Verified against Basalam's own vendor panel figures.
     */
    private function channelDiscount(Order $order, int $itemsTotal, int $fee, string $feeSource): array
    {
        $balanceKey = $order->channel?->config['balance_meta_key'] ?? null;

        if (! $balanceKey || $feeSource !== 'metadata') {
            return [0, 'none'];
        }

        $raw = RawOrderMeta::get($order->rawOrder->payload, $balanceKey);

        if ($raw === null || $raw === '' || (float) $raw === 0.0) {
            return [0, 'none'];
        }

        $discount = $itemsTotal - $fee - (int) round((float) $raw);

        // Basalam's own internal rounding can leave a few Toman of noise —
        // don't report a "discount" for that.
        if ($discount < 100) {
            return [0, 'none'];
        }

        return [$discount, 'metadata'];
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

        $data = [
            'entry_date' => $order->order_date->copy()->setTimezone(JalaliPeriod::TIMEZONE),
            'description' => "سود سفارش {$order->hub_order_id}".($version > 1 ? " (نسخه {$version})" : ''),
            'idempotency_key' => "order:{$order->hub_order_id}:profit:v{$version}",
            'source' => $order,
            'correlation_id' => $order->rawOrder->payload['correlation_id'] ?? null,
        ];

        try {
            return $this->poster->post($data, $lines);
        } catch (PeriodLockedException) {
            // Order dated inside a finalized period: never rewrite history —
            // the entry lands in the current open period and gets flagged.
            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => $data['description']." (ثبت دیرهنگام؛ دوره {$order->jalali_period} قفل است)",
            ] + $data, $lines);

            $this->openOnce('late_entry', $order, ['locked_period' => $order->jalali_period]);

            return $entry;
        }
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
            'channel_discount' => $r['channel_discount'],
            'channel_discount_source' => $r['channel_discount_source'],
            'gateway_fee' => $r['gateway_fee'],
            'package_weight_grams' => $r['package_weight_grams'],
            'packaging_cost' => $r['packaging_cost'],
            'packaging_cost_basis' => $r['packaging_cost_basis'],
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

        $this->creditOrders->reverse($order);
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
