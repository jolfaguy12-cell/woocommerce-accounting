<?php

/*
|--------------------------------------------------------------------------
| Component Showcase Registry
|--------------------------------------------------------------------------
|
| Central catalog for the internal Blade + Alpine component showcase
| (/components, admin only). Every reusable component that the current
| architecture supports is listed here with a STABLE machine id
| (card-01, table-01, chart-01 …). See CLAUDE.md → "Component showcase".
|
| Rules:
|  - IDs are permanent. Never renumber an existing component; new ones get
|    the next free number in their category. IDs must NOT be derived from
|    array position — the explicit 'id' key is the source of truth.
|  - Numbering is independent per category.
|  - Only current Blade + Alpine components belong here (no React/Inertia,
|    no unlocalized template-demo leftovers). Gaps are documented, not faked.
|  - Each component's live preview is rendered by its REAL implementation
|    via resources/views/components/showcase/example.blade.php (keyed on id),
|    with safe mock data supplied by ShowcaseController — never DB fixtures.
|
*/

return [

    // Ordered category metadata. 'noun' drives the Persian reference phrase
    // shown for each component, e.g. «کارت شماره ۱».
    'categories' => [
        'cards' => ['title' => 'کارت‌ها', 'noun' => 'کارت', 'icon' => 'grid'],
        'tables' => ['title' => 'جدول‌ها', 'noun' => 'جدول', 'icon' => 'tables'],
        'charts' => ['title' => 'نمودارها', 'noun' => 'نمودار', 'icon' => 'charts'],
        'forms' => ['title' => 'فرم‌ها', 'noun' => 'فرم', 'icon' => 'forms'],
        'buttons' => ['title' => 'دکمه‌ها و عملیات', 'noun' => 'دکمه', 'icon' => 'ui-elements'],
        'badges' => ['title' => 'نشان‌ها و وضعیت‌ها', 'noun' => 'نشان', 'icon' => 'ui-elements'],
        'alerts' => ['title' => 'اعلان‌ها', 'noun' => 'اعلان', 'icon' => 'bell'],
        'modals' => ['title' => 'مودال‌ها و منوها', 'noun' => 'مودال', 'icon' => 'ui-elements'],
        'navigation' => ['title' => 'اجزای ناوبری', 'noun' => 'ناوبری', 'icon' => 'pages'],
        'states' => ['title' => 'وضعیت‌های خالی و بارگذاری', 'noun' => 'وضعیت', 'icon' => 'task'],
    ],

    'components' => [

        // ---- Cards ----------------------------------------------------
        [
            'id' => 'card-01',
            'category' => 'cards',
            'name' => 'کارت پایه',
            'component' => '<x-common.component-card title desc>',
            'source' => 'resources/views/components/common/component-card.blade.php',
            'description' => 'کارت استاندارد با سربرگ (عنوان + توضیح اختیاری) و بدنه اسلات‌دار؛ ظرف پایه بیشتر بخش‌های صفحات.',
            'variants' => ['با توضیح', 'بدون توضیح'],
        ],
        [
            'id' => 'card-02',
            'category' => 'cards',
            'name' => 'کارت شاخص کلیدی (KPI)',
            'component' => '<x-ecommerce.ecommerce-metrics :kpis :canSeeFinancials>',
            'source' => 'resources/views/components/ecommerce/ecommerce-metrics.blade.php',
            'description' => 'شبکه کارت‌های شاخص داشبورد (مشتریان جدید، فروش ناخالص، موجودی) با درصد تغییر نسبت به ماه قبل.',
            'variants' => ['با دسترسی مالی', 'بدون دسترسی مالی'],
        ],
        [
            'id' => 'card-03',
            'category' => 'cards',
            'name' => 'کارت فهرست قابلیت‌ها',
            'component' => '<x-common.capability-list :available :future :missing>',
            'source' => 'resources/views/components/common/capability-list.blade.php',
            'description' => 'فهرست وضعیت قابلیت‌ها در سه گروه (موجود، آینده، ناموجود) با نشان رنگی؛ در صفحات ابزار استفاده می‌شود.',
            'variants' => ['موجود', 'آینده', 'ناموجود'],
        ],

        // ---- Tables ---------------------------------------------------
        [
            'id' => 'table-01',
            'category' => 'tables',
            'name' => 'جدول داده استاندارد',
            'component' => '<x-tables.data-table :headers :paginator :totals>',
            'source' => 'resources/views/components/tables/data-table.blade.php',
            'description' => 'جدول کارتی به سبک shadcn: سرستون‌های قابل مرتب‌سازی، ستون‌های قابل نمایش/پنهان، ردیف جمع اختیاری و پیام خالی.',
            'variants' => ['ساده', 'با ردیف جمع', 'خالی'],
        ],
        [
            'id' => 'table-02',
            'category' => 'tables',
            'name' => 'جدول حرفه‌ای با نوار ابزار',
            'component' => '<x-tables.pro-table :columns :searchName ...>',
            'source' => 'resources/views/components/tables/pro-table.blade.php',
            'description' => 'پوسته فهرست کامل: جستجو + بازه تاریخ شمسی + کنترل نمایش ستون‌ها روی data-table، همه با فرم GET و بارگذاری کامل صفحه.',
            'variants' => ['با جستجو', 'با بازه تاریخ', 'با کنترل ستون'],
        ],
        [
            'id' => 'table-03',
            'category' => 'tables',
            'name' => 'جدول سفارش‌های اخیر',
            'component' => '<x-ecommerce.recent-orders :orders>',
            'source' => 'resources/views/components/ecommerce/recent-orders.blade.php',
            'description' => 'جدول فشرده داشبورد برای آخرین سفارش‌ها با نشان وضعیت و لینک مشاهده بیشتر.',
            'variants' => ['با داده', 'خالی'],
        ],

        // ---- Charts (ApexCharts, هر کدام شناسه یکتا) -------------------
        [
            'id' => 'chart-01',
            'category' => 'charts',
            'name' => 'نمودار سفارش‌های ماهانه',
            'component' => '<x-ecommerce.monthly-sale :categories :series>',
            'source' => 'resources/views/components/ecommerce/monthly-sale.blade.php',
            'description' => 'نمودار میله‌ای ApexCharts (شناسه chartOne) که داده را از data-categories/data-series می‌خواند؛ روی داشبورد استفاده می‌شود.',
            'variants' => ['میله‌ای', 'داده پویا از سرور'],
        ],
        [
            'id' => 'chart-02',
            'category' => 'charts',
            'name' => 'نمودار هدف ماهانه',
            'component' => '<x-ecommerce.monthly-target />',
            'source' => 'resources/views/components/ecommerce/monthly-target.blade.php',
            'description' => 'گیج رادیال ApexCharts (شناسه chartTwo) برای نمایش درصد تحقق هدف ماه.',
            'variants' => ['رادیال / گیج'],
        ],
        [
            'id' => 'chart-03',
            'category' => 'charts',
            'name' => 'نمودار آماری فروش و درآمد',
            'component' => '<x-ecommerce.statistics-chart />',
            'source' => 'resources/views/components/ecommerce/statistics-chart.blade.php',
            'description' => 'نمودار ناحیه‌ای ApexCharts (شناسه chartThree) با کلید بازه و انتخاب‌گر تاریخ؛ روی داشبورد استفاده می‌شود.',
            'variants' => ['ناحیه‌ای', 'چند سری'],
        ],

        // ---- Forms ----------------------------------------------------
        [
            'id' => 'form-01',
            'category' => 'forms',
            'name' => 'انتخاب‌گر تاریخ',
            'component' => '<x-form.date-picker name label>',
            'source' => 'resources/views/components/form/date-picker.blade.php',
            'description' => 'ورودی تاریخ مبتنی بر Flatpickr با برچسب و آیکن تقویم.',
            'variants' => ['تک‌تاریخ'],
        ],
        [
            'id' => 'form-02',
            'category' => 'forms',
            'name' => 'بازه تاریخ شمسی',
            'component' => '<x-form.jalali-date-range :fromName :toName>',
            'source' => 'resources/views/components/form/jalali-date-range.blade.php',
            'description' => 'انتخاب بازه تاریخ جلالی (کتابخانه vanilla jalalidatepicker) که مقدار میلادی را در فیلدهای مخفی برای سرور می‌گذارد.',
            'variants' => ['بازه از/تا'],
        ],
        [
            'id' => 'form-03',
            'category' => 'forms',
            'name' => 'نوار فیلتر',
            'component' => '<x-common.filter-bar :action :except>',
            'source' => 'resources/views/components/common/filter-bar.blade.php',
            'description' => 'فرم GET که پارامترهای فعال دیگر را حفظ می‌کند؛ هر تغییر فیلتر یک بارگذاری کامل صفحه است (بدون AJAX).',
            'variants' => ['GET با حفظ پارامترها'],
        ],
        [
            'id' => 'form-04',
            'category' => 'forms',
            'name' => 'ورودی مبلغ تومان',
            'component' => 'formatTomanInput(el, hiddenSelector)',
            'source' => 'resources/js/tailadmin/app.js',
            'description' => 'ورودی مبلغ با جداکننده هزارگان هنگام تایپ که مقدار خام عددی را در یک فیلد مخفی برای سرور نگه می‌دارد.',
            'variants' => ['جداکننده هزارگان + فیلد مخفی'],
        ],

        // ---- Buttons & actions ---------------------------------------
        [
            'id' => 'button-01',
            'category' => 'buttons',
            'name' => 'دکمه',
            'component' => '<x-ui.button variant size>',
            'source' => 'resources/views/components/ui/button.blade.php',
            'description' => 'دکمه پایه با گونه‌های primary و outline، اندازه‌های sm و md، آیکن ابتدا/انتها و حالت غیرفعال.',
            'variants' => ['primary', 'outline', 'sm', 'md', 'disabled'],
        ],
        [
            'id' => 'button-02',
            'category' => 'buttons',
            'name' => 'منوی عملیات کشویی',
            'component' => '<x-common.dropdown-menu :items>',
            'source' => 'resources/views/components/common/dropdown-menu.blade.php',
            'description' => 'دکمه سه‌نقطه با منوی کشویی Alpine برای عملیات؛ روی کارت‌ها و ویجت‌ها استفاده می‌شود.',
            'variants' => ['فهرست دلخواه'],
        ],

        // ---- Badges & status -----------------------------------------
        [
            'id' => 'badge-01',
            'category' => 'badges',
            'name' => 'نشان پایه',
            'component' => '<x-ui.badge variant color size>',
            'source' => 'resources/views/components/ui/badge.blade.php',
            'description' => 'نشان رنگی با گونه‌های light و solid و رنگ‌های معنایی (primary/success/error/warning/info/…) و اندازه sm/md.',
            'variants' => ['light', 'solid', 'success', 'error', 'warning', 'info'],
        ],
        [
            'id' => 'badge-02',
            'category' => 'badges',
            'name' => 'نشان وضعیت سفارش',
            'component' => '<x-orders.status-badge type value>',
            'source' => 'resources/views/components/orders/status-badge.blade.php',
            'description' => 'نشان وضعیت با نگاشت برچسب/رنگ فارسی برای وضعیت سفارش، مالی، سود و پرداخت؛ مقدار ناشناخته امن نمایش داده می‌شود.',
            'variants' => ['order', 'financial', 'profit', 'payment'],
        ],
        [
            'id' => 'badge-03',
            'category' => 'badges',
            'name' => 'نشان وضعیت گزارش',
            'component' => '<x-reports.state-badge state>',
            'source' => 'resources/views/components/reports/state-badge.blade.php',
            'description' => 'نشان وضعیت گزارش دوره‌ای (پیش‌نویس، نیازمند بازبینی، نهایی، تعدیل‌شده) با رنگ متناظر.',
            'variants' => ['draft', 'needs_review', 'final', 'adjusted'],
        ],
        [
            'id' => 'badge-04',
            'category' => 'badges',
            'name' => 'آواتار',
            'component' => '<x-ui.avatar size status>',
            'source' => 'resources/views/components/ui/avatar.blade.php',
            'description' => 'آواتار کاربر با اندازه‌های مختلف و نشانگر وضعیت (آنلاین/آفلاین/مشغول).',
            'variants' => ['online', 'offline', 'busy', 'اندازه‌های مختلف'],
        ],

        // ---- Alerts ---------------------------------------------------
        [
            'id' => 'alert-01',
            'category' => 'alerts',
            'name' => 'هشدار',
            'component' => '<x-ui.alert variant title message>',
            'source' => 'resources/views/components/ui/alert.blade.php',
            'description' => 'کادر هشدار با گونه‌های success/error/warning/info، آیکن، عنوان، پیام و اسلات محتوای دلخواه.',
            'variants' => ['success', 'error', 'warning', 'info'],
        ],

        // ---- Modals & menus ------------------------------------------
        [
            'id' => 'modal-01',
            'category' => 'modals',
            'name' => 'مودال پایه',
            'component' => '<x-ui.modal :isOpen :showCloseButton>',
            'source' => 'resources/views/components/ui/modal.blade.php',
            'description' => 'مودال Alpine با پس‌زمینه تار، بستن با Escape/کلیک بیرون و اسلات محتوا؛ باز/بسته با رویداد یا x-data کنترل می‌شود.',
            'variants' => ['با دکمه بستن', 'بدون دکمه بستن'],
        ],
        [
            'id' => 'modal-02',
            'category' => 'modals',
            'name' => 'منوی عملیات ردیف',
            'component' => '<x-common.table-dropdown :items>',
            'source' => 'resources/views/components/common/table-dropdown.blade.php',
            'description' => 'منوی سه‌نقطه ردیف جدول که به سمت جهت خواندنِ دارای فضا باز می‌شود.',
            'variants' => ['فهرست عملیات'],
        ],

        // ---- Navigation ----------------------------------------------
        [
            'id' => 'nav-01',
            'category' => 'navigation',
            'name' => 'مسیر راهنما (بردکرامب)',
            'component' => '<x-common.page-breadcrumb pageTitle parentLabel parentUrl>',
            'source' => 'resources/views/components/common/page-breadcrumb.blade.php',
            'description' => 'سربرگ صفحه با مسیر راهنما (خانه ← والد ← صفحه) و عنوان بزرگ صفحه.',
            'variants' => ['بدون والد', 'با والد'],
        ],
        [
            'id' => 'nav-02',
            'category' => 'navigation',
            'name' => 'کلید تغییر پوسته',
            'component' => '<x-common.theme-toggle />',
            'source' => 'resources/views/components/common/theme-toggle.blade.php',
            'description' => 'دکمه تغییر حالت روشن/تاریک که انتخاب را در localStorage نگه می‌دارد.',
            'variants' => ['روشن/تاریک'],
        ],

        // ---- Empty & loading states ----------------------------------
        [
            'id' => 'state-01',
            'category' => 'states',
            'name' => 'وضعیت خالی',
            'component' => '<x-tables.data-table emptyMessage>',
            'source' => 'resources/views/components/tables/data-table.blade.php',
            'description' => 'حالت «موردی یافت نشد» جدول داده وقتی هیچ ردیفی وجود ندارد.',
            'variants' => ['پیام خالی سفارشی'],
        ],
        [
            'id' => 'state-02',
            'category' => 'states',
            'name' => 'بارگذاری (اسپینر)',
            'component' => '<x-common.preloader />',
            'source' => 'resources/views/components/common/preloader.blade.php',
            'description' => 'اسپینر چرخان برند برای حالت بارگذاری؛ نسخه واقعی یک پوشش تمام‌صفحه است (اینجا در قاب نمونه نشان داده می‌شود).',
            'variants' => ['اسپینر چرخان'],
        ],
    ],
];
