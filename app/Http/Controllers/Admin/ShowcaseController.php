<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Internal, admin-only Blade + Alpine component showcase (/components).
 * Renders every registered reusable component with its real implementation
 * and safe mock data. Metadata lives in config/showcase.php; this controller
 * only groups the registry and supplies preview mock data — it never touches
 * the database or business logic. See CLAUDE.md → "Component showcase".
 */
class ShowcaseController extends Controller
{
    public function overview(): View
    {
        return view('pages.components.overview', [
            'title' => 'کامپوننت‌ها',
            'categories' => $this->categories(),
            'countByCategory' => $this->componentsByCategory()->map->count(),
        ]);
    }

    public function category(string $category): View
    {
        $categories = $this->categories();

        abort_unless(isset($categories[$category]), 404);

        return view('pages.components.category', [
            'title' => $categories[$category]['title'],
            'categoryKey' => $category,
            'meta' => $categories[$category],
            'categories' => $categories,
            'components' => $this->componentsByCategory()->get($category, collect())->values(),
            'mock' => $this->mock(),
        ]);
    }

    /** @return array<string, array{title:string, noun:string, icon:string}> */
    private function categories(): array
    {
        return config('showcase.categories', []);
    }

    /** All registered components grouped by their category key. */
    private function componentsByCategory(): Collection
    {
        return collect(config('showcase.components', []))->groupBy('category');
    }

    /**
     * Safe, isolated mock data for live previews. Realistic Persian sample
     * values only — nothing from the database, no sensitive/production data.
     *
     * @return array<string, mixed>
     */
    private function mock(): array
    {
        return [
            'kpis' => [
                'new_customers' => 128,
                'new_customers_change' => 12.5,
                'gross_sales' => 84_500_000,
                'gross_sales_change' => -3.2,
                'stock_count' => 1_942,
                'stock_count_change' => 4.1,
                'inventory_units' => 1_942,
                'inventory_value' => 512_300_000,
                'inventory_computed_at' => now(),
            ],
            'months' => ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
            'monthlySeries' => [168, 385, 201, 298, 187, 195, 291, 110, 215, 390, 280, 112],
            'recentOrders' => [
                ['id' => 1, 'hub_order_id' => 10482, 'date' => '۱۴۰۵/۰۴/۱۲', 'time' => '۱۴:۳۰', 'total' => 1_250_000, 'status_label' => 'تکمیل‌شده', 'status_color' => 'success'],
                ['id' => 2, 'hub_order_id' => 10481, 'date' => '۱۴۰۵/۰۴/۱۲', 'time' => '۱۱:۰۵', 'total' => 640_000, 'status_label' => 'در حال پردازش', 'status_color' => 'warning'],
                ['id' => 3, 'hub_order_id' => 10480, 'date' => '۱۴۰۵/۰۴/۱۱', 'time' => '۰۹:۴۸', 'total' => 3_180_000, 'status_label' => 'لغوشده', 'status_color' => 'error'],
            ],
            'capabilities' => [
                'available' => ['ثبت سفارش از هاب', 'محاسبه سود سفارش', 'گزارش دوره‌ای شرکا'],
                'future' => ['غنی‌سازی کانال از API', 'اپلیکیشن موبایل'],
                'missing' => ['نمودار زیان محصولات'],
            ],
        ];
    }
}
