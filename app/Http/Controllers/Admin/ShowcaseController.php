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

            // ---- Phase 3 -------------------------------------------------
            'profitRows' => [
                ['label' => 'فروش خالص', 'value' => 84_500_000],
                ['label' => 'بهای تمام‌شده کالا', 'value' => -52_300_000],
                ['label' => 'کارمزد کانال‌ها', 'value' => -3_100_000],
                ['label' => 'هزینه حمل', 'value' => -4_800_000],
            ],
            'cashflowRows' => [
                ['label' => 'موجودی بانک و صندوق', 'value' => 128_400_000],
                ['label' => 'مطالبات از مشتریان', 'value' => 42_900_000],
                ['label' => 'بدهی به تأمین‌کنندگان', 'value' => -31_500_000],
                ['label' => 'چک‌های پرداختنی', 'value' => -12_000_000, 'status' => 'pending'],
            ],
            'revenueByChannel' => [
                ['label' => 'وب‌سایت', 'value' => 48_200_000],
                ['label' => 'باسلام', 'value' => 21_500_000],
                ['label' => 'ترب', 'value' => 9_800_000],
                ['label' => 'دیجی‌کالا', 'value' => 5_000_000],
            ],
            'expenseByCategory' => [
                ['label' => 'تبلیغات', 'value' => 12_500_000],
                ['label' => 'حقوق و دستمزد', 'value' => 28_000_000],
                ['label' => 'اجاره', 'value' => 8_000_000],
                ['label' => 'حمل و بسته‌بندی', 'value' => 4_800_000],
            ],
            'bridgeSteps' => [
                ['label' => 'سود ناخالص', 'value' => 32_200_000],
                ['label' => 'هزینه‌های عملیاتی', 'value' => -12_500_000],
                ['label' => 'حقوق', 'value' => -8_000_000],
                ['label' => 'کارمزد کانال', 'value' => -3_100_000],
                ['label' => 'تعدیلات', 'value' => 1_200_000],
            ],
            'topProducts' => [
                ['label' => 'اسپری ضدعفونی‌کننده ۵۰۰ml', 'value' => 18_400_000, 'meta' => '۲۴۰ فروش'],
                ['label' => 'ژل شست‌وشوی دست', 'value' => 12_100_000, 'meta' => '۱۸۵ فروش'],
                ['label' => 'ماسک سه‌لایه (بسته ۵۰ عددی)', 'value' => 7_900_000, 'meta' => '۱۴۰ فروش'],
                ['label' => 'دستکش نیتریل', 'value' => 4_200_000, 'meta' => '۹۵ فروش'],
            ],
            'topCustomers' => [
                ['label' => 'داروخانه مرکزی', 'value' => 22_500_000, 'meta' => '۱۸ سفارش', 'status' => 'completed'],
                ['label' => 'زهرا کریمی', 'value' => 9_300_000, 'meta' => '۷ سفارش'],
                ['label' => 'محمد صادقی', 'value' => 6_100_000, 'meta' => '۵ سفارش', 'status' => 'pending'],
            ],
            'activities' => [
                ['title' => 'گزارش دوره ۱۴۰۵-۰۳ نهایی شد', 'meta' => 'علی خلیلی', 'time' => '۱۴۰۵/۰۴/۱۲ ۱۴:۳۰', 'tone' => 'success', 'status' => 'completed'],
                ['title' => 'فاکتور خرید #۱۰۴ ثبت شد', 'meta' => 'مبلغ ۱۲٬۸۰۰٬۰۰۰ تومان', 'time' => '۱۴۰۵/۰۴/۱۲ ۱۱:۰۵', 'tone' => 'default'],
                ['title' => 'منبع ناشناخته در سفارش #۱۰۴۸۲', 'meta' => 'نیازمند نگاشت کانال', 'time' => '۱۴۰۵/۰۴/۱۱ ۰۹:۴۸', 'tone' => 'warning', 'status' => 'needs_review'],
                ['title' => 'همگام‌سازی سفارش‌ها ناموفق بود', 'meta' => 'خطای اتصال به هاب', 'time' => '۱۴۰۵/۰۴/۱۰ ۲۳:۱۵', 'tone' => 'error', 'status' => 'failed'],
            ],
            'quickStats' => [
                ['label' => 'سفارش امروز', 'value' => 24, 'type' => 'int', 'change' => 8.3],
                ['label' => 'فروش امروز', 'value' => 4_820_000, 'type' => 'toman', 'change' => -2.1],
                ['label' => 'میانگین سبد', 'value' => 200_800, 'type' => 'toman', 'change' => 1.4],
                ['label' => 'در انتظار بازبینی', 'value' => 3, 'type' => 'int', 'status' => 'needs_review'],
            ],
            'todos' => [
                ['id' => 1, 'title' => 'بستن گزارش دوره ۱۴۰۵-۰۴', 'done' => false, 'priority' => 'high', 'due' => '۱۴۰۵/۰۴/۳۱', 'overdue' => false, 'status' => 'processing', 'assignee' => 'علی خلیلی'],
                ['id' => 2, 'title' => 'نگاشت کانال منبع ناشناخته', 'done' => false, 'priority' => 'high', 'due' => '۱۴۰۵/۰۴/۱۰', 'overdue' => true, 'status' => 'needs_review'],
                ['id' => 3, 'title' => 'ثبت بهای تمام‌شده ۵ محصول', 'done' => false, 'priority' => 'medium', 'due' => '۱۴۰۵/۰۵/۰۵', 'overdue' => false],
                ['id' => 4, 'title' => 'بارگذاری واریزی‌های زیبال', 'done' => true, 'priority' => 'low', 'due' => '۱۴۰۵/۰۴/۰۸', 'overdue' => false, 'status' => 'completed'],
            ],
        ];
    }
}
