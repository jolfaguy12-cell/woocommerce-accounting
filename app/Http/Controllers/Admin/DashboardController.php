<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Products\Services\InventorySnapshotService;
use App\Domain\Reports\Services\DashboardMetricsService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetricsService $metrics, InventorySnapshotService $inventory): View
    {
        $period = JalaliPeriod::fromDate(Carbon::now(JalaliPeriod::TIMEZONE));
        // Sales/customer-growth are financial data, hidden from warehouse-only
        // users; stock count and recent orders are not (warehouse already
        // reads full order/product detail elsewhere via /orders, /products).
        $canSeeFinancials = $request->user()->hasAnyRole(['admin', 'accountant', 'partner_viewer']);

        $stats = $metrics->monthStats($period);
        $inventorySnapshot = $inventory->latest();

        return view('pages.dashboard.ecommerce', [
            'title' => 'داشبورد',
            'canSeeFinancials' => $canSeeFinancials,
            'kpis' => [
                'new_customers' => $canSeeFinancials ? $stats['new_customers'] : null,
                'new_customers_change' => $canSeeFinancials ? $metrics->percentChange('new_customers', $period) : null,
                'gross_sales' => $canSeeFinancials ? $stats['gross_sales'] : null,
                'gross_sales_change' => $canSeeFinancials ? $metrics->percentChange('gross_sales', $period) : null,
                'stock_count' => $stats['stock_count'],
                'stock_count_change' => $metrics->percentChange('stock_count', $period),
                'inventory_units' => $inventorySnapshot?->total_units,
                'inventory_value' => $inventorySnapshot?->total_value,
                'inventory_computed_at' => $inventorySnapshot?->computed_at,
            ],
            'monthlyOrderCounts' => $metrics->yearlyOrderCounts($period),
            'recentOrders' => $metrics->recentOrders(10),
        ]);
    }
}
