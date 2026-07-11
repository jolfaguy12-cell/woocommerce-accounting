@props(['kpis' => [], 'canSeeFinancials' => false])

@php
    /** Green "up X%" / red "down X%" / neutral "—" badge — null means nothing to compare against yet. */
    $trendBadge = function (?float $pct) {
        if ($pct === null) {
            return ['classes' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400', 'icon' => null, 'text' => '—'];
        }
        $up = $pct >= 0;

        return [
            'classes' => $up
                ? 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500'
                : 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            'icon' => $up
                ? '<path fill-rule="evenodd" clip-rule="evenodd" d="M5.56462 1.62393C5.70193 1.47072 5.90135 1.37432 6.12329 1.37432C6.1236 1.37432 6.12391 1.37432 6.12422 1.37432C6.31631 1.37415 6.50845 1.44731 6.65505 1.59381L9.65514 4.5918C9.94814 4.88459 9.94831 5.35947 9.65552 5.65246C9.36273 5.94546 8.88785 5.94562 8.59486 5.65283L6.87329 3.93247L6.87329 10.125C6.87329 10.5392 6.53751 10.875 6.12329 10.875C5.70908 10.875 5.37329 10.5392 5.37329 10.125L5.37329 3.93578L3.65516 5.65282C3.36218 5.94562 2.8873 5.94547 2.5945 5.65248C2.3017 5.35949 2.30185 4.88462 2.59484 4.59182L5.56462 1.62393Z" fill="" />'
                : '<path fill-rule="evenodd" clip-rule="evenodd" d="M5.31462 10.3761C5.45194 10.5293 5.65136 10.6257 5.87329 10.6257C5.8736 10.6257 5.8739 10.6257 5.87421 10.6257C6.0663 10.6259 6.25845 10.5527 6.40505 10.4062L9.40514 7.4082C9.69814 7.11541 9.69831 6.64054 9.40552 6.34754C9.11273 6.05454 8.63785 6.05438 8.34486 6.34717L6.62329 8.06753L6.62329 1.875C6.62329 1.46079 6.28751 1.125 5.87329 1.125C5.45908 1.125 5.12329 1.46079 5.12329 1.875L5.12329 8.06422L3.40516 6.34719C3.11218 6.05439 2.6373 6.05454 2.3445 6.34752C2.0517 6.64051 2.05185 7.11538 2.34484 7.40818L5.31462 10.3761Z" fill="" />',
            'text' => number_format(abs($pct), 1).'٪',
        ];
    };

    $customersTrend = $trendBadge($kpis['new_customers_change'] ?? null);
    $salesTrend = $trendBadge($kpis['gross_sales_change'] ?? null);
    $stockTrend = $trendBadge($kpis['stock_count_change'] ?? null);
@endphp

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6">

  {{-- 1. تعداد مشتریان جدید این ماه --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-brand-50 dark:bg-brand-500/15">
      <svg class="stroke-brand-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="8" r="4" />
        <path d="M4 20c0-4.4 3.6-8 8-8s8 3.6 8 8" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">مشتریان جدید این ماه</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">{{ $canSeeFinancials ? number_format($kpis['new_customers'] ?? 0) : '—' }}</h4>
      </div>

      @if ($canSeeFinancials)
        <span class="flex items-center gap-1 rounded-full py-0.5 pr-2 pl-2.5 text-sm font-medium {{ $customersTrend['classes'] }}">
          @if ($customersTrend['icon'])
            <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">{!! $customersTrend['icon'] !!}</svg>
          @endif
          {{ $customersTrend['text'] }}
        </span>
      @endif
    </div>
  </div>

  {{-- 2. فروش کل (ناخالص) این ماه --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-success-50 dark:bg-success-500/15">
      <svg class="stroke-success-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 17l6-6 4 4 8-8" />
        <path d="M15 7h6v6" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">فروش کل (ناخالص)</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90" dir="ltr">{{ $canSeeFinancials ? number_format($kpis['gross_sales'] ?? 0).' تومان' : '—' }}</h4>
      </div>

      @if ($canSeeFinancials)
        <span class="flex items-center gap-1 rounded-full py-0.5 pr-2 pl-2.5 text-sm font-medium {{ $salesTrend['classes'] }}">
          @if ($salesTrend['icon'])
            <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">{!! $salesTrend['icon'] !!}</svg>
          @endif
          {{ $salesTrend['text'] }}
        </span>
      @endif
    </div>
  </div>

  {{-- 3. محصول موجود در انبار (تعداد محصولات یکتا با موجودی مثبت) --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-blue-light-50 dark:bg-blue-light-500/15">
      <svg class="stroke-blue-light-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 8l-9-5-9 5 9 5 9-5z" />
        <path d="M3 8v8l9 5 9-5V8" />
        <path d="M12 13v8" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">محصول موجود در انبار</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">{{ number_format($kpis['stock_count'] ?? 0) }} محصول</h4>
      </div>

      <span class="flex items-center gap-1 rounded-full py-0.5 pr-2 pl-2.5 text-sm font-medium {{ $stockTrend['classes'] }}">
        @if ($stockTrend['icon'])
          <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">{!! $stockTrend['icon'] !!}</svg>
        @endif
        {{ $stockTrend['text'] }}
      </span>
    </div>
  </div>

  {{-- 4. کالاهای موجود در انبار (مجموع دقیق واحدهای فیزیکی، شامل تنوع‌ها) — هر چند ساعت یک‌بار محاسبه می‌شود، نه با هر رفرش --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-500/15">
      <svg class="stroke-teal-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="7" width="18" height="13" rx="1.5" />
        <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">کالاهای موجود در انبار</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">
          {{ $kpis['inventory_units'] !== null ? number_format($kpis['inventory_units']).' عدد' : '—' }}
        </h4>
      </div>
    </div>
    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
      {{ $kpis['inventory_computed_at'] ? 'به‌روزرسانی '.\App\Domain\Accounting\Support\JalaliPeriod::humanDiff($kpis['inventory_computed_at']) : 'هنوز محاسبه نشده' }}
    </p>
  </div>

  {{-- 5. ارزش انبار سایت (بر مبنای قیمت فروش، نه بهای تمام‌شده) — همراه با کارت قبل محاسبه می‌شود --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-gray-100 dark:bg-gray-800">
      <svg class="stroke-gray-500 dark:stroke-gray-300" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 21V10l9-6 9 6v11" />
        <path d="M9 21v-6h6v6" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">ارزش انبار سایت (به قیمت فروش)</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90" dir="ltr">
          {{ $kpis['inventory_value'] !== null ? number_format($kpis['inventory_value']).' تومان' : '—' }}
        </h4>
      </div>
    </div>
    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
      {{ $kpis['inventory_computed_at'] ? 'به‌روزرسانی '.\App\Domain\Accounting\Support\JalaliPeriod::humanDiff($kpis['inventory_computed_at']) : 'هنوز محاسبه نشده' }}
    </p>
  </div>

  {{-- 6. هزینه‌های ماه --}}
  {{-- TODO(backend): SUM(Expense.amount) برای دوره جاری (jalali_period فعلی) --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-error-50 dark:bg-error-500/15">
      <svg class="stroke-error-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M8 12h8" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">هزینه‌های ماه</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">۷۴,۵۰۰,۰۰۰ تومان</h4>
      </div>

      <span class="flex items-center gap-1 rounded-full bg-error-50 py-0.5 pr-2 pl-2.5 text-sm font-medium text-error-600 dark:bg-error-500/15 dark:text-error-500">
        <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path fill-rule="evenodd" clip-rule="evenodd" d="M5.31462 10.3761C5.45194 10.5293 5.65136 10.6257 5.87329 10.6257C5.8736 10.6257 5.8739 10.6257 5.87421 10.6257C6.0663 10.6259 6.25845 10.5527 6.40505 10.4062L9.40514 7.4082C9.69814 7.11541 9.69831 6.64054 9.40552 6.34754C9.11273 6.05454 8.63785 6.05438 8.34486 6.34717L6.62329 8.06753L6.62329 1.875C6.62329 1.46079 6.28751 1.125 5.87329 1.125C5.45908 1.125 5.12329 1.46079 5.12329 1.875L5.12329 8.06422L3.40516 6.34719C3.11218 6.05439 2.6373 6.05454 2.3445 6.34752C2.0517 6.64051 2.05185 7.11538 2.34484 7.40818L5.31462 10.3761Z" fill="" />
        </svg>
        5.10%
      </span>
    </div>
  </div>

  {{-- 7. درآمد ماه --}}
  {{-- TODO(backend): جمع net_sale برای دوره جاری (همان fin.kpis.net_sales در DashboardController) --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-success-50 dark:bg-success-500/15">
      <svg class="stroke-success-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 8v8M8 12h8" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">درآمد ماه</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">۲۱۸,۳۰۰,۰۰۰ تومان</h4>
      </div>

      <span class="flex items-center gap-1 rounded-full bg-success-50 py-0.5 pr-2 pl-2.5 text-sm font-medium text-success-600 dark:bg-success-500/15 dark:text-success-500">
        <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path fill-rule="evenodd" clip-rule="evenodd" d="M5.56462 1.62393C5.70193 1.47072 5.90135 1.37432 6.12329 1.37432C6.1236 1.37432 6.12391 1.37432 6.12422 1.37432C6.31631 1.37415 6.50845 1.44731 6.65505 1.59381L9.65514 4.5918C9.94814 4.88459 9.94831 5.35947 9.65552 5.65246C9.36273 5.94546 8.88785 5.94562 8.59486 5.65283L6.87329 3.93247L6.87329 10.125C6.87329 10.5392 6.53751 10.875 6.12329 10.875C5.70908 10.875 5.37329 10.5392 5.37329 10.125L5.37329 3.93578L3.65516 5.65282C3.36218 5.94562 2.8873 5.94547 2.5945 5.65248C2.3017 5.35949 2.30185 4.88462 2.59484 4.59182L5.56462 1.62393Z" fill="" />
        </svg>
        9.60%
      </span>
    </div>
  </div>

  {{-- 8. مانده حساب --}}
  {{-- TODO(backend): جمع مانده حساب‌ها (fin.balances.banks_and_cash + receivables - payables ...) --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-orange-50 dark:bg-orange-500/15">
      <svg class="stroke-orange-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 3v18" />
        <path d="M5 7h14" />
        <path d="M5 7l-3 6a3 3 0 0 0 6 0z" />
        <path d="M19 7l-3 6a3 3 0 0 0 6 0z" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">مانده حساب</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">۳۴۰,۹۰۰,۰۰۰ تومان</h4>
      </div>

      <span class="flex items-center gap-1 rounded-full bg-success-50 py-0.5 pr-2 pl-2.5 text-sm font-medium text-success-600 dark:bg-success-500/15 dark:text-success-500">
        <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path fill-rule="evenodd" clip-rule="evenodd" d="M5.56462 1.62393C5.70193 1.47072 5.90135 1.37432 6.12329 1.37432C6.1236 1.37432 6.12391 1.37432 6.12422 1.37432C6.31631 1.37415 6.50845 1.44731 6.65505 1.59381L9.65514 4.5918C9.94814 4.88459 9.94831 5.35947 9.65552 5.65246C9.36273 5.94546 8.88785 5.94562 8.59486 5.65283L6.87329 3.93247L6.87329 10.125C6.87329 10.5392 6.53751 10.875 6.12329 10.875C5.70908 10.875 5.37329 10.5392 5.37329 10.125L5.37329 3.93578L3.65516 5.65282C3.36218 5.94562 2.8873 5.94547 2.5945 5.65248C2.3017 5.35949 2.30185 4.88462 2.59484 4.59182L5.56462 1.62393Z" fill="" />
        </svg>
        2.30%
      </span>
    </div>
  </div>

  {{-- 9. درآمد روز --}}
  {{-- TODO(backend): جمع net_sale سفارش‌های امروز (order_date = امروز به وقت تهران) --}}
  <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-warning-50 dark:bg-warning-500/15">
      <svg class="stroke-warning-500" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M16 3v4M8 3v4M3 10h18" />
        <circle cx="12" cy="15" r="2.5" />
      </svg>
    </div>

    <div class="flex items-end justify-between mt-5">
      <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">درآمد روز</span>
        <h4 class="mt-2 font-bold text-gray-800 text-title-sm dark:text-white/90">۱۲,۴۰۰,۰۰۰ تومان</h4>
      </div>

      <span class="flex items-center gap-1 rounded-full bg-error-50 py-0.5 pr-2 pl-2.5 text-sm font-medium text-error-600 dark:bg-error-500/15 dark:text-error-500">
        <svg class="fill-current" width="12" height="12" viewBox="0 0 12 12" fill="none">
          <path fill-rule="evenodd" clip-rule="evenodd" d="M5.31462 10.3761C5.45194 10.5293 5.65136 10.6257 5.87329 10.6257C5.8736 10.6257 5.8739 10.6257 5.87421 10.6257C6.0663 10.6259 6.25845 10.5527 6.40505 10.4062L9.40514 7.4082C9.69814 7.11541 9.69831 6.64054 9.40552 6.34754C9.11273 6.05454 8.63785 6.05438 8.34486 6.34717L6.62329 8.06753L6.62329 1.875C6.62329 1.46079 6.28751 1.125 5.87329 1.125C5.45908 1.125 5.12329 1.46079 5.12329 1.875L5.12329 8.06422L3.40516 6.34719C3.11218 6.05439 2.6373 6.05454 2.3445 6.34752C2.0517 6.64051 2.05185 7.11538 2.34484 7.40818L5.31462 10.3761Z" fill="" />
        </svg>
        1.80%
      </span>
    </div>
  </div>

</div>
