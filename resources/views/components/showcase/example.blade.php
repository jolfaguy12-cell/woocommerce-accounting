@props(['id', 'mock' => []])

{{--
    Live preview renderer for the component showcase. Each case renders the
    REAL component (never a copy) with the safe mock data from
    ShowcaseController. Keyed on the stable registry id (config/showcase.php).
    Charts reuse the actual dashboard widgets, each with its own unique
    ApexCharts element id, so no two chart ids collide on one page.
--}}
@switch($id)

    {{-- ============ Design tokens ============ --}}
    @case('token-01')
        <div class="flex flex-wrap gap-3">
            @foreach ([['profit', 'سود'], ['loss', 'زیان'], ['income', 'درآمد'], ['expense', 'هزینه'], ['trend-up', 'روند صعودی'], ['trend-down', 'روند نزولی']] as [$t, $fa])
                <div class="flex items-center gap-2 rounded-control border border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
                    <span class="h-4 w-4 rounded-badge" style="background-color: var(--color-{{ $t }});"></span>
                    <span class="text-theme-xs text-gray-700 dark:text-gray-300">{{ $fa }}</span>
                    <code class="text-[10px] text-gray-400" dir="ltr">--color-{{ $t }}</code>
                </div>
            @endforeach
        </div>
        @break

    @case('token-02')
        <div class="flex flex-wrap gap-2">
            @foreach (array_keys(\App\Support\Design\StatusPresenter::all()) as $st)
                <x-ui.status :status="$st" />
            @endforeach
        </div>
        @break

    @case('token-03')
        <div class="flex flex-wrap items-end gap-4">
            <div class="rounded-card border border-gray-200 bg-white p-4 shadow-card dark:border-gray-800 dark:bg-white/[0.03]">
                <code class="text-theme-xs text-gray-500" dir="ltr">rounded-card + shadow-card</code>
            </div>
            <div class="rounded-control border border-gray-200 bg-white p-4 shadow-dropdown dark:border-gray-800 dark:bg-white/[0.03]">
                <code class="text-theme-xs text-gray-500" dir="ltr">rounded-control + shadow-dropdown</code>
            </div>
            <span class="rounded-badge bg-brand-50 px-3 py-1 text-theme-xs text-brand-600 dark:bg-brand-500/15 dark:text-brand-400" dir="ltr">rounded-badge</span>
        </div>
        @break

    {{-- ============ KPI ============ --}}
    @case('kpi-01')
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-kpi.card label="فروش ناخالص" :value="84500000" unit="تومان" :change="12.5" variant="financial"
                :sparkline="[12, 18, 15, 22, 19, 28, 31]" />
            <x-kpi.card label="سود عملیاتی" :value="-3200000" unit="تومان" :change="-3.2" variant="financial" />
            <x-kpi.card label="تحقق هدف ماه" :value="75" unit="٪" :progress="75.5" variant="goal" status="processing" />
        </div>
        @break

    {{-- ============ Cards ============ --}}
    @case('card-01')
        <x-common.component-card title="نمونه کارت" desc="توضیح کوتاه زیر عنوان کارت.">
            <p class="text-sm text-gray-600 dark:text-gray-300">محتوای دلخواه کارت در اینجا قرار می‌گیرد.</p>
        </x-common.component-card>
        @break

    @case('card-02')
        <x-ecommerce.ecommerce-metrics :kpis="$mock['kpis']" :canSeeFinancials="true" />
        @break

    @case('card-03')
        <x-common.capability-list
            :available="$mock['capabilities']['available']"
            :future="$mock['capabilities']['future']"
            :missing="$mock['capabilities']['missing']" />
        @break

    {{-- ============ Tables ============ --}}
    @case('table-01')
        <x-tables.data-table
            :headers="['نام مشتری', ['label' => 'مبلغ'], 'وضعیت']"
            :totals="[['label' => 'جمع', 'value' => '۴٬۰۷۰٬۰۰۰ تومان']]">
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">زهرا کریمی</td>
                <x-tables.num :value="1250000" />
                <td class="px-5 py-3"><x-ui.badge color="success" size="sm">تکمیل‌شده</x-ui.badge></td>
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">محمد صادقی</td>
                <x-tables.num :value="2820000" />
                <td class="px-5 py-3"><x-ui.badge color="warning" size="sm">در حال پردازش</x-ui.badge></td>
            </tr>
        </x-tables.data-table>
        @break

    @case('table-02')
        <x-tables.pro-table
            storageKey="showcase.proTable"
            searchPlaceholder="جستجوی مشتری"
            :columns="[
                ['key' => 'name', 'label' => 'نام مشتری'],
                ['key' => 'amount', 'label' => 'مبلغ'],
                ['key' => 'status', 'label' => 'وضعیت'],
            ]">
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.name" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">زهرا کریمی</td>
                <x-tables.num x-show="visible.amount" :value="1250000" />
                <td x-show="visible.status" class="px-5 py-3"><x-ui.badge color="success" size="sm">تکمیل‌شده</x-ui.badge></td>
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.name" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">محمد صادقی</td>
                <x-tables.num x-show="visible.amount" :value="2820000" />
                <td x-show="visible.status" class="px-5 py-3"><x-ui.badge color="warning" size="sm">در حال پردازش</x-ui.badge></td>
            </tr>
        </x-tables.pro-table>
        @break

    @case('table-03')
        <x-ecommerce.recent-orders :orders="$mock['recentOrders']" />
        @break

    @case('table-04')
        <x-tables.data-table :headers="['شرح', ['label' => 'مبلغ (تومان)'], ['label' => 'سود/زیان']]">
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">فروش دوره</td>
                <x-tables.num :value="1250000" />
                <x-tables.num :value="284000" :signed="true" />
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">بازگشت کالا</td>
                <x-tables.num :value="12820000" />
                <x-tables.num :value="-45000" :signed="true" />
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">بدون داده</td>
                <x-tables.num :value="null" />
                <x-tables.num :value="null" :signed="true" />
            </tr>
        </x-tables.data-table>
        <p class="mt-2 text-theme-xs text-gray-400">ارقام با طول متفاوت زیر هم می‌نشینند و با هدر هم‌تراز می‌مانند.</p>
        @break

    @case('table-05')
        @php $rowIds = [101, 102, 103]; @endphp
        <x-tables.pro-table
            storageKey="showcase.bulkTable"
            searchPlaceholder="جستجو"
            :columns="[
                ['key' => 'name', 'label' => 'شرح'],
                ['key' => 'amount', 'label' => 'مبلغ (تومان)'],
                ['key' => 'status', 'label' => 'وضعیت'],
            ]">
            <x-slot:bulkActions>
                <button type="button" class="h-8 rounded-control bg-brand-500 px-3 text-theme-xs font-medium text-white hover:bg-brand-600">تأیید گروهی</button>
            </x-slot:bulkActions>

            @foreach ([[101, 'فاکتور خرید ۱۰۱', 1250000, 'completed'], [102, 'فاکتور خرید ۱۰۲', 640000, 'pending'], [103, 'فاکتور خرید ۱۰۳', 3180000, 'failed']] as [$rid, $name, $amt, $st])
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td x-show="visible.name" class="px-5 py-3 sm:px-6">
                        <label class="flex items-center gap-2 text-gray-800 dark:text-white/90">
                            <input type="checkbox" value="{{ $rid }}" x-model.number="selected"
                                class="h-4 w-4 rounded border-gray-300 text-brand-500 dark:border-gray-700 dark:bg-gray-900">
                            {{ $name }}
                        </label>
                    </td>
                    <x-tables.num x-show="visible.amount" :value="$amt" />
                    <td x-show="visible.status" class="px-5 py-3"><x-ui.status :status="$st" /></td>
                </tr>
            @endforeach
        </x-tables.pro-table>
        @break

    {{-- ============ Charts ============ --}}
    @case('chart-01')
        <x-ecommerce.monthly-sale :categories="$mock['months']" :series="$mock['monthlySeries']" />
        @break

    @case('chart-02')
        <x-ecommerce.monthly-target />
        @break

    @case('chart-03')
        <x-ecommerce.statistics-chart />
        @break

    @case('chart-04')
        {{-- Two instances of the SAME preset on one page — impossible under the
             old hard-coded-id scheme; proves the ids are gone. --}}
        <div class="grid gap-4 md:grid-cols-2">
            <x-common.component-card title="نمودار خطی">
                <x-charts.chart preset="line" height="sm" :categories="$mock['months']" :series="$mock['monthlySeries']" />
            </x-common.component-card>
            <x-common.component-card title="همان پرست، نمونهٔ دوم">
                <x-charts.chart preset="line" height="sm" :categories="$mock['months']" :series="array_reverse($mock['monthlySeries'])" />
            </x-common.component-card>
        </div>
        @break

    @case('chart-05')
        <div class="grid gap-4 md:grid-cols-2">
            <x-common.component-card title="تفکیک هزینه‌ها (دونات)">
                <x-charts.chart preset="donut" height="sm"
                    :categories="['تبلیغات', 'حقوق', 'حمل', 'اجاره']"
                    :series="[35, 40, 15, 10]" />
            </x-common.component-card>
            <x-common.component-card title="عملکرد کانال‌ها (میله افقی)">
                <x-charts.chart preset="bar-horizontal" height="sm"
                    :categories="['وب‌سایت', 'باسلام', 'ترب', 'دیجی‌کالا']"
                    :series="[420, 310, 180, 95]" />
            </x-common.component-card>
        </div>
        @break

    {{-- ============ Forms ============ --}}
    @case('form-01')
        <div class="max-w-xs">
            <x-form.date-picker name="showcase_date" label="تاریخ" placeholder="انتخاب تاریخ" />
        </div>
        @break

    @case('form-02')
        <x-form.jalali-date-range fromName="showcase_from" toName="showcase_to" />
        @break

    @case('form-03')
        <x-common.filter-bar action="#">
            <select name="status" onchange="this.form.submit()"
                class="h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="">همه وضعیت‌ها</option>
                <option value="completed">تکمیل‌شده</option>
                <option value="processing">در حال پردازش</option>
            </select>
            <button type="submit" class="h-10 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        </x-common.filter-bar>
        @break

    @case('form-04')
        <div class="max-w-xs">
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ (تومان)</label>
            <input type="text" dir="ltr" inputmode="numeric" placeholder="۰"
                oninput="formatTomanInput(this, '#showcase-toman-hidden')"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <input type="hidden" id="showcase-toman-hidden" name="showcase_amount">
            <p class="mt-1 text-xs text-gray-400">هنگام تایپ جداکننده هزارگان اضافه می‌شود؛ مقدار خام در فیلد مخفی می‌رود.</p>
        </div>
        @break

    @case('form-05')
        <form class="flex flex-wrap items-center gap-6" onsubmit="return false;">
            <div class="flex items-center gap-2">
                <x-ui.toggle-switch name="showcase_toggle_on" :checked="true" />
                <span class="text-theme-xs text-gray-600 dark:text-gray-300">فعال</span>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.toggle-switch name="showcase_toggle_off" :checked="false" />
                <span class="text-theme-xs text-gray-600 dark:text-gray-300">غیرفعال</span>
            </div>
            <div class="flex items-center gap-2">
                <x-ui.toggle-switch name="showcase_toggle_disabled" :checked="true" :disabled="true" />
                <span class="text-theme-xs text-gray-600 dark:text-gray-300">قفل‌شده (disabled)</span>
            </div>
        </form>
        @break

    @case('form-06')
        <div class="max-w-xs">
            <x-form.money-input name="showcase_money" label="مبلغ" :value="1250000" />
            <p class="mt-2 text-theme-xs text-gray-400">
                روی صفحه ۱٬۲۵۰٬۰۰۰ دیده می‌شود؛ چیزی که ارسال می‌شود «1250000» است.
            </p>
        </div>
        @break

    @case('form-07')
        <div class="max-w-xs">
            <x-form.jalali-date name="showcase_date" label="تاریخ سررسید" :value="now()->toDateString()" />
            <p class="mt-2 text-theme-xs text-gray-400">
                کاربر شمسی می‌بیند؛ فیلد مخفی مقدار میلادی Y-m-d را می‌فرستد.
            </p>
        </div>
        @break

    @case('form-08')
        <div class="max-w-md">
            <x-form.party-select name="showcase_party" label="طرف حساب"
                help="جستجو و صفحه‌بندی سمت سرور روی همه طرف حساب‌ها." />
        </div>
        @break

    {{-- ============ Buttons & actions ============ --}}
    @case('button-01')
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button variant="primary">دکمه اصلی</x-ui.button>
            <x-ui.button variant="outline">دکمه فرعی</x-ui.button>
            <x-ui.button variant="primary" size="sm">اندازه کوچک</x-ui.button>
            <x-ui.button variant="primary" :disabled="true">غیرفعال</x-ui.button>
        </div>
        @break

    @case('button-02')
        <x-common.dropdown-menu :items="['ویرایش', 'حذف', 'مشاهده جزئیات']" />
        @break

    {{-- ============ Badges & status ============ --}}
    @case('badge-01')
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge color="primary">اصلی</x-ui.badge>
            <x-ui.badge color="success">موفق</x-ui.badge>
            <x-ui.badge color="error">خطا</x-ui.badge>
            <x-ui.badge color="warning">هشدار</x-ui.badge>
            <x-ui.badge color="info">اطلاع</x-ui.badge>
            <x-ui.badge variant="solid" color="success">توپُر</x-ui.badge>
        </div>
        @break

    @case('badge-02')
        <div class="flex flex-wrap items-center gap-2">
            <x-orders.status-badge type="order" value="completed" />
            <x-orders.status-badge type="order" value="cancelled" />
            <x-orders.status-badge type="payment" value="paid" />
            <x-orders.status-badge type="profit" value="needs_review" />
        </div>
        @break

    @case('badge-03')
        <div class="flex flex-wrap items-center gap-2">
            <x-reports.state-badge state="draft" />
            <x-reports.state-badge state="needs_review" />
            <x-reports.state-badge state="final" />
            <x-reports.state-badge state="adjusted" />
        </div>
        @break

    @case('badge-04')
        @php
            $avatarSrc = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#465fff"/><text x="20" y="26" font-size="18" fill="#fff" text-anchor="middle" font-family="sans-serif">ب</text></svg>');
        @endphp
        <div class="flex flex-wrap items-center gap-4">
            <x-ui.avatar :src="$avatarSrc" size="small" status="online" />
            <x-ui.avatar :src="$avatarSrc" size="medium" status="offline" />
            <x-ui.avatar :src="$avatarSrc" size="large" status="busy" />
        </div>
        @break

    @case('badge-05')
        <div class="flex flex-wrap items-center gap-2">
            @foreach (array_keys(\App\Support\Design\StatusPresenter::all()) as $st)
                <x-ui.status :status="$st" />
            @endforeach
        </div>
        @break

    {{-- ============ Alerts ============ --}}
    @case('alert-01')
        <div class="space-y-3">
            <x-ui.alert variant="success" title="موفق" message="عملیات با موفقیت انجام شد." />
            <x-ui.alert variant="warning" title="هشدار" message="برخی موارد نیازمند بررسی هستند." />
            <x-ui.alert variant="error" title="خطا" message="در ثبت اطلاعات مشکلی پیش آمد." />
            <x-ui.alert variant="info" title="اطلاع" message="این یک پیام اطلاع‌رسانی است." />
        </div>
        @break

    {{-- ============ Modals & menus ============ --}}
    @case('modal-01')
        <div x-data>
            <button type="button" @click="$dispatch('open-showcase-modal')"
                class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                باز کردن مودال
            </button>
            {{-- The x-on handler passes through the modal's $attributes onto its
                 Alpine root (where `open` lives) — same trigger mechanism the
                 real app pages use to open shared modals. --}}
            <x-ui.modal x-on:open-showcase-modal.window="open = true" class="max-w-md p-6">
                <h4 class="mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">عنوان مودال</h4>
                <p class="text-sm text-gray-500 dark:text-gray-400">محتوای مودال در اینجا قرار می‌گیرد. برای بستن، کلید Escape را بزنید یا بیرون کادر کلیک کنید.</p>
            </x-ui.modal>
        </div>
        @break

    @case('modal-02')
        <x-common.table-dropdown>
            <x-slot:button>
                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                </button>
            </x-slot:button>
            <x-slot:content>
                <button class="block w-full rounded-lg px-3 py-2 text-right text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">ویرایش</button>
                <button class="block w-full rounded-lg px-3 py-2 text-right text-sm text-error-500 hover:bg-gray-100 dark:hover:bg-white/5">حذف</button>
            </x-slot:content>
        </x-common.table-dropdown>
        @break

    {{-- ============ Navigation ============ --}}
    @case('nav-01')
        <div class="rounded-lg bg-white p-4 dark:bg-gray-900">
            <x-common.page-breadcrumb pageTitle="صفحه نمونه" parentLabel="بخش والد" parentUrl="#" />
        </div>
        @break

    @case('nav-02')
        <x-common.theme-toggle />
        @break

    {{-- ============ Empty & loading states ============ --}}
    @case('state-01')
        @php $emptyPaginator = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15); @endphp
        <x-tables.data-table
            :headers="['نام مشتری', 'مبلغ', 'وضعیت']"
            :paginator="$emptyPaginator"
            emptyMessage="موردی یافت نشد" />
        @break

    @case('state-02')
        {{-- Contained version of <x-common.preloader>'s spinner (the real one is
             a fixed full-screen overlay, unsuitable for an inline preview). --}}
        <div class="flex items-center justify-center rounded-xl border border-gray-200 bg-white p-10 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="h-16 w-16 animate-spin rounded-full border-4 border-solid border-brand-500 border-t-transparent"></div>
        </div>
        @break

    @case('state-03')
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['empty', 'no-results', 'error', 'permission', 'loading'] as $v)
                <div class="rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                    <x-states.state :variant="$v" />
                </div>
            @endforeach
            <div class="rounded-card border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-3 text-theme-xs text-gray-400">skeleton</p>
                <x-states.state variant="skeleton" :rows="4" />
            </div>
        </div>
        @break


    {{-- ============ Tables 06–11 (Phase 3) ============ --}}
    @case('table-06')
        <x-tables.data-table :headers="['شرح', ['label' => 'مبلغ (تومان)']]">
            @foreach ([['فروش', $mock['revenueByChannel']], ['هزینه', $mock['expenseByCategory']]] as [$group, $rows])
                <tr class="bg-gray-50 dark:bg-white/[0.02]">
                    <td colspan="2" class="px-5 py-2 text-theme-xs font-semibold text-gray-700 sm:px-6 dark:text-gray-200">{{ $group }}</td>
                </tr>
                @foreach ($rows as $r)
                    <tr class="border-b border-gray-50 dark:border-gray-800/60">
                        <td class="px-5 py-2.5 pr-10 text-theme-sm text-gray-600 sm:px-6 dark:text-gray-300">{{ $r['label'] }}</td>
                        <x-tables.num :value="$r['value']" type="toman" class="text-theme-sm" />
                    </tr>
                @endforeach
                <tr class="border-b border-gray-200 dark:border-gray-800">
                    <td class="px-5 py-2 text-theme-xs font-medium text-gray-500 sm:px-6">جمع {{ $group }}</td>
                    <x-tables.num :value="collect($rows)->sum('value')" type="toman" class="text-theme-sm font-semibold" />
                </tr>
            @endforeach
        </x-tables.data-table>
        @break

    @case('table-07')
        <x-tables.data-table :headers="['شرح', ['label' => 'این دوره'], ['label' => 'دوره قبل'], ['label' => 'تغییر']]">
            @foreach ([['فروش خالص', 84500000, 75100000], ['سود عملیاتی', 16600000, 18200000], ['تعداد سفارش', 412, 388]] as [$label, $now, $prev])
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">{{ $label }}</td>
                    <x-tables.num :value="$now" class="text-theme-sm" />
                    <x-tables.num :value="$prev" class="text-theme-sm" tone="subtle" />
                    <x-tables.num :value="$prev ? (($now - $prev) / $prev) * 100 : null" type="percent" :signed="true" class="text-theme-sm" />
                </tr>
            @endforeach
        </x-tables.data-table>
        @break

    @case('table-08')
        <x-tables.data-table :headers="['رتبه', 'محصول', ['label' => 'سهم'], ['label' => 'فروش (تومان)']]">
            @php $tot = collect($mock['topProducts'])->sum('value'); @endphp
            @foreach ($mock['topProducts'] as $i => $p)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 sm:px-6">
                        <span class="flex h-6 w-6 items-center justify-center rounded-badge bg-gray-100 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $i + 1 }}</span>
                    </td>
                    <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ $p['label'] }}</td>
                    <x-tables.num :value="$tot ? ($p['value'] / $tot) * 100 : 0" type="percent" class="text-theme-xs" tone="subtle" />
                    <x-tables.num :value="$p['value']" type="toman" class="text-theme-sm" />
                </tr>
            @endforeach
        </x-tables.data-table>
        @break

    @case('table-09')
        {{-- Row expand is Alpine-only; the detail rows are already server-rendered. --}}
        <div x-data="{ open: null }">
            <x-tables.data-table :headers="['فاکتور', 'تأمین‌کننده', ['label' => 'مبلغ (تومان)'], '']">
                @foreach ([[104, 'پخش البرز', 12800000, [['ماسک سه‌لایه', 50, 8000000], ['دستکش نیتریل', 20, 4800000]]], [103, 'داروسازی بهار', 6400000, [['ژل ضدعفونی', 40, 6400000]]]] as [$inv, $sup, $amt, $lines])
                    <tr class="cursor-pointer border-b border-gray-100 dark:border-gray-800" @click="open = open === {{ $inv }} ? null : {{ $inv }}">
                        <x-tables.ltr :value="'#'.$inv" tone="brand" class="text-theme-sm font-medium" />
                        <td class="px-5 py-3 text-theme-sm text-gray-800 dark:text-white/90">{{ $sup }}</td>
                        <x-tables.num :value="$amt" type="toman" class="text-theme-sm" />
                        <td class="px-5 py-3 text-theme-xs text-gray-400">
                            <span x-text="open === {{ $inv }} ? 'بستن' : 'جزئیات'"></span>
                        </td>
                    </tr>
                    <tr x-show="open === {{ $inv }}" x-cloak class="bg-gray-50 dark:bg-white/[0.02]">
                        <td colspan="4" class="px-5 py-3 sm:px-6">
                            <table class="w-full">
                                @foreach ($lines as [$item, $qty, $line])
                                    <tr>
                                        <td class="py-1.5 text-theme-xs text-gray-600 dark:text-gray-300">{{ $item }}</td>
                                        <x-tables.num :value="$qty" class="py-1.5 text-theme-xs" unit="عدد" />
                                        <x-tables.num :value="$line" type="toman" class="py-1.5 text-theme-xs" />
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endforeach
            </x-tables.data-table>
        </div>
        @break

    @case('table-10')
        <x-tables.data-table :headers="['طرف حساب', 'سررسید', ['label' => 'مبلغ (تومان)'], 'وضعیت']">
            @foreach ([['داروخانه مرکزی', '۱۴۰۵/۰۴/۲۵', 12500000, 'pending', false], ['زهرا کریمی', '۱۴۰۵/۰۴/۰۵', 3200000, 'failed', true], ['محمد صادقی', '۱۴۰۵/۰۵/۱۰', 8400000, 'approved', false]] as [$party, $due, $amt, $st, $overdue])
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">{{ $party }}</td>
                    <td class="px-5 py-3 text-theme-sm {{ $overdue ? 'font-medium text-loss' : 'text-gray-600 dark:text-gray-300' }}">
                        {{ $due }}
                        @if ($overdue)<span class="text-theme-xs">(معوق)</span>@endif
                    </td>
                    <x-tables.num :value="$amt" type="toman" class="text-theme-sm" />
                    <td class="px-5 py-3"><x-ui.status :status="$st" /></td>
                </tr>
            @endforeach
        </x-tables.data-table>
        @break

    @case('table-11')
        <x-tables.data-table :headers="['کاربر', 'عملیات', 'زمان', ['label' => 'قبل'], ['label' => 'بعد']]">
            @foreach ([['علی خلیلی', 'تغییر بهای تمام‌شده', '۱۴۰۵/۰۴/۱۲ ۱۴:۳۰', 400000, 420000], ['سیستم', 'بازمحاسبه سود سفارش', '۱۴۰۵/۰۴/۱۲ ۱۱:۰۵', 281000, 261000]] as [$user, $act, $time, $before, $after])
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">{{ $user }}</td>
                    <td class="px-5 py-3 text-theme-sm text-gray-600 dark:text-gray-300">{{ $act }}</td>
                    <td class="px-5 py-3 text-theme-xs text-gray-400">{{ $time }}</td>
                    <x-tables.num :value="$before" type="toman" class="text-theme-xs" tone="subtle" />
                    <x-tables.num :value="$after" type="toman" class="text-theme-sm" />
                </tr>
            @endforeach
        </x-tables.data-table>
        @break

    @case('table-12')
        <x-tables.data-table :headers="['مشتری', ['label' => 'شماره تماس'], ['label' => 'شبا'], ['label' => 'مبلغ (تومان)']]">
            @foreach ([['زهرا کریمی', '09121234567', 'IR820540102680020817909002', 1250000], ['محمد صادقی', '09354446677', 'IR060120000000004652147001', 640000]] as [$name, $phone, $iban, $amt])
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 text-theme-sm text-gray-800 sm:px-6 dark:text-white/90">{{ $name }}</td>
                    <x-tables.ltr :value="$phone" mono tone="muted" class="text-theme-sm" />
                    <x-tables.ltr :value="$iban" mono tone="muted" class="text-theme-xs" />
                    <x-tables.num :value="$amt" type="toman" class="text-theme-sm" />
                </tr>
            @endforeach
        </x-tables.data-table>
        <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">شناسه‌های لاتین و اعداد، هر دو به لبهٔ راست (شروع خواندن در RTL) چسبیده‌اند و با هدر هم‌تراز می‌مانند.</p>
        @break

    {{-- ============ Charts 06–08 ============ --}}
    @case('chart-06')
        <x-common.component-card title="فروش تجمعی در برابر هدف">
            <x-charts.chart preset="cumulative-target" height="md" :categories="array_slice($mock['months'], 0, 6)"
                :series="[
                    ['name' => 'محقق‌شده', 'data' => [12, 18, 15, 22, 19, 24]],
                    ['name' => 'هدف', 'data' => [18, 18, 18, 18, 18, 18]],
                ]" />
        </x-common.component-card>
        @break

    @case('chart-07')
        <x-common.component-card title="درآمد و تعداد سفارش">
            <x-charts.chart preset="revenue-orders" height="md" :categories="array_slice($mock['months'], 0, 6)"
                :series="[
                    ['name' => 'درآمد (تومان)', 'data' => [48000000, 52000000, 61000000, 58000000, 72000000, 84500000]],
                    ['name' => 'تعداد سفارش', 'data' => [220, 245, 290, 275, 340, 412]],
                ]" />
        </x-common.component-card>
        @break

    @case('chart-08')
        <x-common.component-card title="پل سود ناخالص تا خالص">
            <x-charts.chart preset="pnl-bridge" height="md"
                :categories="array_column($mock['bridgeSteps'], 'label')"
                :series="array_column($mock['bridgeSteps'], 'value')" />
        </x-common.component-card>
        @break

    {{-- ============ Financial ============ --}}
    @case('fin-01')
        <div class="grid gap-4 md:grid-cols-2">
            <x-financial.summary title="خلاصه سود دوره" desc="۱۴۰۵/۰۴"
                :rows="$mock['profitRows']"
                :total="['label' => 'سود عملیاتی', 'value' => 24300000, 'signed' => true]" />
            <x-financial.summary title="جریان نقدی و مانده‌ها" status="processing"
                :rows="$mock['cashflowRows']"
                :total="['label' => 'خالص', 'value' => 127800000, 'signed' => true]" />
        </div>
        @break

    @case('fin-02')
        <div class="grid gap-4 md:grid-cols-2">
            <x-financial.breakdown title="درآمد به تفکیک کانال" :items="$mock['revenueByChannel']" chart="donut" />
            <x-financial.breakdown title="هزینه به تفکیک دسته" :items="$mock['expenseByCategory']" chart="bar-horizontal" />
        </div>
        @break

    @case('fin-03')
        <x-financial.bridge :steps="$mock['bridgeSteps']" desc="۱۴۰۵/۰۴" />
        @break

    {{-- ============ Dashboard widgets ============ --}}
    @case('wid-01')
        <div class="grid gap-4 md:grid-cols-2">
            <x-widgets.ranked-list title="پرفروش‌ترین محصولات" :items="$mock['topProducts']" moreUrl="#" />
            <x-widgets.ranked-list title="برترین مشتریان" :items="$mock['topCustomers']" moreUrl="#" />
        </div>
        @break

    @case('wid-02')
        <x-widgets.timeline title="فعالیت‌های اخیر" :items="$mock['activities']" moreUrl="#" />
        @break

    @case('wid-03')
        <x-widgets.quick-stats title="آمار امروز" :stats="$mock['quickStats']" />
        @break

    @case('wid-04')
        <div class="max-w-md">
            <x-widgets.target-progress :actual="84500000" :target="100000000"
                :categories="array_slice($mock['months'], 0, 6)"
                :series="[12, 18, 15, 22, 19, 24]" :targetSeries="[18, 18, 18, 18, 18, 18]" />
        </div>
        @break

    {{-- ============ TODO ============ --}}
    @case('todo-01')
        <div class="grid gap-4 md:grid-cols-2">
            <x-todo.list title="کارهای من" :items="$mock['todos']" :interactive="true" />
            <x-todo.list title="کارهای پیش‌رو (فقط‌خواندنی)" :items="array_slice($mock['todos'], 0, 3)"
                :interactive="false" :showProgress="false" />
        </div>
        @break

    {{-- ============ Filters ============ --}}
    @case('filter-01')
        <x-filters.chips clearUrl="#" :filters="[
            ['label' => 'جستجو', 'value' => 'اسپری', 'url' => '#'],
            ['label' => 'وضعیت', 'value' => 'تکمیل‌شده', 'url' => '#'],
            ['label' => 'کانال', 'value' => 'باسلام', 'url' => '#'],
        ]" />
        @break

    @case('filter-02')
        <x-filters.panel title="فیلتر پیشرفته" :activeCount="2" :open="true" clearUrl="#" storageKey="showcase.panel">
            <div>
                <label class="mb-1.5 block text-theme-xs text-gray-500 dark:text-gray-400">وضعیت</label>
                <select name="status" class="h-10 w-full rounded-control border border-gray-300 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option>همه</option><option>تکمیل‌شده</option><option>در انتظار</option>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-theme-xs text-gray-500 dark:text-gray-400">کانال فروش</label>
                <select name="channel" class="h-10 w-full rounded-control border border-gray-300 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option>همه</option><option>وب‌سایت</option><option>باسلام</option>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-theme-xs text-gray-500 dark:text-gray-400">بازه تاریخ</label>
                <x-form.jalali-date-range fromName="showcase_f" toName="showcase_t" />
            </div>
        </x-filters.panel>
        @break

    @case('filter-03')
        <div class="flex flex-wrap gap-3">
            <x-filters.quick name="showcase_period" active="month" :options="['today' => 'امروز', 'week' => 'این هفته', 'month' => 'این ماه', 'year' => 'امسال']" />
            <x-filters.quick name="showcase_cmp" active="prev" :options="['prev' => 'دوره قبل', 'year' => 'سال قبل']" />
        </div>
        @break

    {{-- ============ Navigation ============ --}}
    @case('nav-03')
        <div class="space-y-4">
            <x-nav.tabs param="showcase_tab" :tabs="[
                'overview' => 'نمای کلی',
                'sales' => 'فروش',
                'expenses' => 'هزینه‌ها',
            ]" />
            <x-nav.tabs :panels="true" :tabs="['a' => 'تب محلی ۱', 'b' => 'تب محلی ۲']">
                <div x-show="tab === 'a'" class="text-sm text-gray-600 dark:text-gray-300">محتوای تب اول (Alpine محلی، بدون رفت‌وبرگشت به سرور).</div>
                <div x-show="tab === 'b'" x-cloak class="text-sm text-gray-600 dark:text-gray-300">محتوای تب دوم.</div>
            </x-nav.tabs>
        </div>
        @break

    @case('nav-04')
        @php
            $p = new \Illuminate\Pagination\LengthAwarePaginator(range(1, 15), 240, 15, 3, ['path' => request()->url()]);
        @endphp
        <div class="rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <x-nav.pagination :paginator="$p" :perPage="15" :perPageUrl="fn ($s) => '#per='.$s" />
        </div>
        @break

    @case('nav-05')
        <x-nav.section-header title="گزارش دوره ۱۴۰۵-۰۴" desc="سود و زیان دوره، بر اساس سفارش‌های نهایی‌شده" status="processing">
            <x-slot:actions>
                <button class="h-9 rounded-control border border-gray-300 px-3 text-theme-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">خروجی اکسل</button>
                <button class="h-9 rounded-control bg-brand-500 px-3 text-theme-sm font-medium text-white hover:bg-brand-600">نهایی‌سازی</button>
            </x-slot:actions>
        </x-nav.section-header>
        @break

    {{-- ============ State banners ============ --}}
    @case('state-04')
        <div class="space-y-3">
            <x-states.state variant="stale" :action="['label' => 'همگام‌سازی', 'url' => '#']" />
            <x-states.state variant="partial" />
            <x-states.state variant="offline" />
        </div>
        @break

    @default
        <p class="text-sm text-error-500">پیش‌نمایشی برای «{{ $id }}» تعریف نشده است.</p>
@endswitch
