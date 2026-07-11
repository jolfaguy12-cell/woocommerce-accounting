<?php

namespace App\Domain\Reports\Services;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Support\OrderStatusPresenter;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Reports\Models\MonthlyDashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

/**
 * Dashboard KPIs built on "valid" orders (excludes pending-payment and
 * cancelled/void/refunded — the same bucket used on the Orders and Customers
 * pages). A fully-closed Jalali month is computed once and frozen in
 * monthly_dashboard_snapshots so a dashboard refresh never re-scans full
 * order history; the current (still open) month is always computed live
 * and its row kept up to date, so once it closes, it already has a real
 * stock_count on hand for the "vs last month" comparison instead of nothing.
 */
class DashboardMetricsService
{
    private const VALID_STATES = ['valid'];

    public function monthStats(string $period): array
    {
        $currentPeriod = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));

        if ($period === $currentPeriod) {
            $stats = $this->compute($period);
            MonthlyDashboardSnapshot::updateOrCreate(
                ['jalali_period' => $period],
                array_merge($stats, ['computed_at' => now()])
            );

            return $stats;
        }

        if ($snapshot = MonthlyDashboardSnapshot::firstWhere('jalali_period', $period)) {
            return [
                'new_customers' => $snapshot->new_customers,
                'orders_count' => $snapshot->orders_count,
                'gross_sales' => $snapshot->gross_sales,
                'stock_count' => $snapshot->stock_count,
            ];
        }

        // First time this (closed) period has ever been asked for. Orders/customers
        // are reconstructible from immutable order history, but stock_count is a
        // live mirrored value with no historical log — it can't be known for a
        // month that closed before anyone ever loaded the dashboard during it.
        $stats = $this->compute($period);
        $stats['stock_count'] = null;
        MonthlyDashboardSnapshot::updateOrCreate(
            ['jalali_period' => $period],
            array_merge($stats, ['computed_at' => now()])
        );

        return $stats;
    }

    /** Current period's value vs the prior period's, as a percentage — null when there is nothing meaningful to compare against. */
    public function percentChange(string $metric, string $period): ?float
    {
        $current = $this->monthStats($period)[$metric];
        $previous = $this->monthStats(JalaliPeriod::previous($period))[$metric];

        if ($current === null || $previous === null) {
            return null;
        }
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null; // growth "from zero" has no meaningful percentage
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** Valid-order counts for every month of the Jalali year $period falls in — months after $period haven't happened yet. */
    public function yearlyOrderCounts(string $period): array
    {
        return collect(JalaliPeriod::monthsOfYear($period))
            ->mapWithKeys(fn ($m) => [$m => $m > $period ? 0 : $this->monthStats($m)['orders_count']])
            ->all();
    }

    /** Latest orders of any status, for the dashboard's recent-orders widget (warehouse can already see these on /orders). */
    public function recentOrders(int $limit = 10): array
    {
        return Order::with('channel:id,name')
            ->latest('order_date')
            ->limit($limit)
            ->get(['id', 'hub_order_id', 'status', 'total', 'order_date', 'channel_id'])
            ->map(function (Order $order) {
                $local = $order->order_date->copy()->setTimezone(JalaliPeriod::TIMEZONE);

                return [
                    'id' => $order->id,
                    'hub_order_id' => $order->hub_order_id,
                    'date' => Jalalian::fromCarbon($local)->format('Y/m/d'),
                    'time' => $local->format('H:i'),
                    'total' => (int) $order->total,
                    'status' => $order->status,
                    'status_label' => OrderStatusPresenter::orderStatus($order->status)['label'],
                    'status_color' => OrderStatusPresenter::orderStatus($order->status)['color'],
                ];
            })
            ->all();
    }

    private function compute(string $period): array
    {
        return [
            'new_customers' => DB::table('orders')
                ->select('customer_party_id')
                ->whereIn('financial_state', self::VALID_STATES)
                ->whereNotNull('customer_party_id')
                ->groupBy('customer_party_id')
                ->havingRaw('MIN(jalali_period) = ?', [$period])
                ->count(),
            'orders_count' => Order::where('jalali_period', $period)->whereIn('financial_state', self::VALID_STATES)->count(),
            'gross_sales' => (int) Order::where('jalali_period', $period)->whereIn('financial_state', self::VALID_STATES)->sum('total'),
            'stock_count' => ProductMirror::whereIn('type', ['simple', 'variation'])
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'trash'))
                ->where('stock_quantity', '>', 0)
                ->count(),
        ];
    }
}
