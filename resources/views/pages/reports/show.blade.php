@extends('layouts.app')

@php
    $jp = \App\Domain\Accounting\Support\JalaliPeriod::class;
    $d = $report['data'];
    $orderLabels = [
        'count' => 'تعداد سفارش',
        'gross_sales' => 'فروش ناخالص',
        'discounts' => 'تخفیف‌ها',
        'net_sales' => 'فروش خالص',
        'product_cost' => 'بهای تمام‌شده',
        'shipping_charged' => 'حمل دریافتی',
        'shipping_real' => 'حمل واقعی',
        'channel_fees' => 'کارمزد کانال‌ها',
        'gross_profit' => 'سود ناخالص',
        'operational_profit' => 'سود عملیاتی',
        'average_order_value' => 'میانگین سفارش',
        'provisional_count' => 'سود موقت (بازبینی)',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="گزارش دوره {{ $report['jalali_period'] }}" parentLabel="گزارش‌ها" parentUrl="{{ route('reports.index') }}" />

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        @if ($report['is_snapshot'])
            <x-ui.badge color="success" size="sm">نهایی{{ $report['state'] === 'adjusted' ? ' + تعدیل' : '' }} (snapshot)</x-ui.badge>
        @else
            <x-ui.badge color="light" size="sm">پیش‌نویس زنده</x-ui.badge>
        @endif
        @if ($report['finalized_at'])
            <span class="text-sm text-gray-500 dark:text-gray-400">نهایی‌شده: {{ $jp::fmtDateTime($report['finalized_at']) }}</span>
        @endif
    </div>

    @if (! $report['is_snapshot'] && ! $report['readiness']['ready'])
        <x-ui.alert variant="warning" title="چک‌لیست آمادگی">
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @foreach ($report['readiness']['issues'] as $key => $value)
                    <x-ui.badge color="error" size="sm">{{ $key }}: {{ number_format($value) }}</x-ui.badge>
                @endforeach
            </div>
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-common.component-card title="عملکرد سفارش‌ها" class="lg:col-span-2">
            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm md:grid-cols-3">
                @foreach ($d['orders'] as $key => $value)
                    <div class="flex justify-between border-b border-gray-100 py-1 dark:border-gray-800">
                        <span class="text-gray-500 dark:text-gray-400">{{ $orderLabels[$key] ?? $key }}</span>
                        <span class="text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($value) }}</span>
                    </div>
                @endforeach
            </div>
        </x-common.component-card>

        <x-common.component-card title="جمع‌بندی دوره">
            <div class="space-y-1 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500 dark:text-gray-400">سود عملیاتی سفارش‌ها</span>
                    <span class="text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($d['orders']['operational_profit']) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500 dark:text-gray-400">هزینه‌های مؤثر بر شرکا</span>
                    <span class="text-error-500" dir="ltr">-{{ number_format($d['expenses']['total_affecting_partner']) }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500 dark:text-gray-400">حقوق دوره</span>
                    <span class="text-error-500" dir="ltr">-{{ number_format($d['payroll']) }}</span>
                </div>
                @foreach ($d['channel_costs'] as $slug => $cost)
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">هزینه کانال {{ $slug }}</span>
                        <span class="text-error-500" dir="ltr">-{{ number_format($cost) }}</span>
                    </div>
                @endforeach
                <div class="flex justify-between border-t border-gray-100 pt-2 text-base font-bold dark:border-gray-800">
                    <span class="text-gray-800 dark:text-white/90">سود خالص دوره</span>
                    <span class="{{ $d['net_period_profit'] < 0 ? 'text-error-500' : 'text-success-600 dark:text-success-400' }}" dir="ltr">{{ number_format($d['net_period_profit']) }}</span>
                </div>
            </div>
        </x-common.component-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-common.component-card title="کانال‌ها">
            <div class="space-y-1 text-sm">
                @forelse ($d['channels'] as $slug => $channel)
                    <div class="flex justify-between border-b border-gray-100 py-1 last:border-0 dark:border-gray-800">
                        <span class="text-gray-800 dark:text-white/90">{{ $channel['name'] }} ({{ number_format($channel['orders']) }})</span>
                        <span class="{{ $channel['final_profitability'] < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">{{ number_format($channel['final_profitability']) }}</span>
                    </div>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">داده‌ای نیست</p>
                @endforelse
            </div>
        </x-common.component-card>

        <x-common.component-card title="هزینه‌ها به تفکیک دسته">
            <div class="space-y-1 text-sm">
                @forelse ($d['expenses']['by_category'] as $name => $total)
                    <div class="flex justify-between border-b border-gray-100 py-1 last:border-0 dark:border-gray-800">
                        <span class="text-gray-500 dark:text-gray-400">{{ $name }}</span>
                        <span class="text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($total) }}</span>
                    </div>
                @empty
                    <p class="text-gray-500 dark:text-gray-400">هزینه‌ای ثبت نشده</p>
                @endforelse
            </div>
        </x-common.component-card>
    </div>

    @if ($report['adjustments']->isNotEmpty())
        <x-common.component-card title="تعدیلات پس از نهایی‌سازی">
            <div class="space-y-1 text-sm">
                @foreach ($report['adjustments'] as $adjustment)
                    <div class="flex justify-between border-b border-gray-100 py-1 last:border-0 dark:border-gray-800">
                        <span class="text-gray-800 dark:text-white/90">{{ $adjustment->description }}</span>
                        <span class="text-gray-500 dark:text-gray-400" dir="ltr">{{ $adjustment->journalEntry?->jalali_period }} · {{ substr($adjustment->journalEntry?->uuid ?? '', 0, 8) }}</span>
                    </div>
                @endforeach
            </div>
        </x-common.component-card>
    @endif

    @if (! $report['is_snapshot'] && $can_finalize)
        <x-common.component-card title="نهایی‌سازی">
            <form method="POST" action="{{ route('reports.finalize', $report['jalali_period']) }}" onsubmit="return confirm('گزارش snapshot و دوره قفل می‌شود. ادامه؟')" class="flex flex-wrap items-center gap-3">
                @csrf
                @error('finalize')
                    <p class="w-full text-sm text-error-500">{{ $message }}</p>
                @enderror
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" name="acknowledge" value="1">
                    موارد باز را می‌پذیرم و آگاهانه نهایی می‌کنم
                </label>
                <button type="submit" class="rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600">
                    نهایی‌سازی و قفل دوره
                </button>
            </form>
        </x-common.component-card>
    @endif
</div>
@endsection
