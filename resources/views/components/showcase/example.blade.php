@props(['id', 'mock' => []])

{{--
    Live preview renderer for the component showcase. Each case renders the
    REAL component (never a copy) with the safe mock data from
    ShowcaseController. Keyed on the stable registry id (config/showcase.php).
    Charts reuse the actual dashboard widgets, each with its own unique
    ApexCharts element id, so no two chart ids collide on one page.
--}}
@switch($id)

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
            :headers="['نام مشتری', 'مبلغ', 'وضعیت']"
            :totals="[['label' => 'جمع', 'value' => '۴٬۰۷۰٬۰۰۰ تومان']]">
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">زهرا کریمی</td>
                <td class="px-5 py-3 text-gray-600 dark:text-gray-300" dir="ltr">۱٬۲۵۰٬۰۰۰</td>
                <td class="px-5 py-3"><x-ui.badge color="success" size="sm">تکمیل‌شده</x-ui.badge></td>
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">محمد صادقی</td>
                <td class="px-5 py-3 text-gray-600 dark:text-gray-300" dir="ltr">۲٬۸۲۰٬۰۰۰</td>
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
                <td x-show="visible.name" class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">زهرا کریمی</td>
                <td x-show="visible.amount" class="px-5 py-3 text-gray-600 dark:text-gray-300" dir="ltr">۱٬۲۵۰٬۰۰۰</td>
                <td x-show="visible.status" class="px-5 py-3"><x-ui.badge color="success" size="sm">تکمیل‌شده</x-ui.badge></td>
            </tr>
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.name" class="px-5 py-3 sm:px-6 text-gray-800 dark:text-white/90">محمد صادقی</td>
                <td x-show="visible.amount" class="px-5 py-3 text-gray-600 dark:text-gray-300" dir="ltr">۲٬۸۲۰٬۰۰۰</td>
                <td x-show="visible.status" class="px-5 py-3"><x-ui.badge color="warning" size="sm">در حال پردازش</x-ui.badge></td>
            </tr>
        </x-tables.pro-table>
        @break

    @case('table-03')
        <x-ecommerce.recent-orders :orders="$mock['recentOrders']" />
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

    @default
        <p class="text-sm text-error-500">پیش‌نمایشی برای «{{ $id }}» تعریف نشده است.</p>
@endswitch
