<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Orders\Models\Order;
use App\Domain\Reports\Services\PartnerReportService;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\SyncRun;
use App\Domain\Sync\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Morilog\Jalali\Jalalian;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PartnerReportService $reports): Response
    {
        $user = $request->user();
        // Financial numbers (profit, sales, balances) are hidden from warehouse-only users.
        $canSeeFinancials = $user->hasAnyRole(['admin', 'accountant', 'partner_viewer']);

        $period = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));
        $data = $canSeeFinancials ? $reports->build($period)->draftData() : [];

        return Inertia::render('dashboard', [
            'dashboard' => [
                'period' => $period,
                'can_see_financials' => $canSeeFinancials,
                'financials' => $canSeeFinancials ? [
                    'kpis' => $this->kpis($data),
                    'trend' => $this->trend(),
                    'channels' => $data['channels'] ?? [],
                    'balances' => $data['balances'] ?? [],
                ] : null,
                'operations' => [
                    'review' => ReviewItem::where('status', 'open')
                        ->groupBy('type')->selectRaw('type, COUNT(*) as total')
                        ->pluck('total', 'type')->map(fn ($v) => (int) $v)->all(),
                    'sync' => [
                        'webhooks' => WebhookEvent::groupBy('status')->selectRaw('status, COUNT(*) as total')
                            ->pluck('total', 'status')->map(fn ($v) => (int) $v)->all(),
                        'last_order_poll' => SyncRun::where('type', 'poll_orders')->where('status', 'done')
                            ->latest('finished_at')->value('finished_at')?->toIso8601String(),
                        'dead_events' => WebhookEvent::where('status', 'dead')->count(),
                    ],
                    'blocked_orders' => Order::where('profit_status', 'blocked_missing_cost')->count(),
                    'unknown_source_orders' => Order::where('profit_status', 'unknown_source')->count(),
                ],
            ],
        ]);
    }

    private function kpis(array $data): array
    {
        return [
            'net_sales' => $data['orders']['net_sales'] ?? 0,
            'operational_profit' => $data['orders']['operational_profit'] ?? 0,
            'net_period_profit' => $data['net_period_profit'] ?? 0,
            'orders' => $data['orders']['count'] ?? 0,
            'average_order_value' => $data['orders']['average_order_value'] ?? 0,
            'expenses' => $data['expenses']['total_affecting_partner'] ?? 0,
        ];
    }

    /** Daily net sales / operational profit for the last 30 days (posted profits only). */
    private function trend(): array
    {
        $since = Carbon::now(JalaliPeriod::TIMEZONE)->subDays(30)->startOfDay();

        return DB::table('order_profits')
            ->join('orders', 'orders.id', '=', 'order_profits.order_id')
            ->whereIn('order_profits.status', ['final', 'provisional'])
            ->where('orders.order_date', '>=', $since)
            ->groupByRaw('DATE(orders.order_date)')
            ->selectRaw('DATE(orders.order_date) as date, SUM(order_profits.net_sale) as net_sales, SUM(order_profits.operational_profit) as operational_profit, COUNT(*) as orders')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'label' => Jalalian::fromCarbon(Carbon::parse($row->date))->format('m/d'),
                'net_sales' => (int) $row->net_sales,
                'operational_profit' => (int) $row->operational_profit,
                'orders' => (int) $row->orders,
            ])
            ->all();
    }
}
