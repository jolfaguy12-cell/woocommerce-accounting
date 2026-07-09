@extends('layouts.app')

@php
    $fmt = fn ($iso) => $iso ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($iso)) : '—';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="وضعیت سیستم" />

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">خروجی زنده همان بررسی که دستور acc:health انجام می‌دهد.</p>
        <x-ui.badge :color="$status['ok'] ? 'success' : 'error'">{{ $status['ok'] ? 'سالم' : 'نیازمند بررسی' }}</x-ui.badge>
    </div>

    <x-common.component-card title="وضعیت اجزای سیستم">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">اتصال دیتابیس</span>
                <x-ui.badge :color="$status['database'] ? 'success' : 'error'">{{ $status['database'] ? 'برقرار' : 'قطع' }}</x-ui.badge>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">اتصال به هاب (Hub)</span>
                <x-ui.badge :color="$status['hub'] ? 'success' : 'error'">{{ $status['hub'] ? 'برقرار' : 'قطع' }}</x-ui.badge>
            </div>
            @if ($status['hub_error'])
                <div class="flex items-center justify-between py-2.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">خطای هاب</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $status['hub_error'] }}</span>
                </div>
            @endif
            @foreach ([
                'صف‌های در انتظار (jobs)' => $status['pending_jobs'],
                'صف‌های ناموفق (failed jobs)' => $status['failed_jobs'],
                'وبهوک‌های مرده (dead)' => $status['dead_webhook_events'],
                'آیتم‌های باز در صف بازبینی' => $status['open_review_items'],
            ] as $label => $value)
                <div class="flex items-center justify-between py-2.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ number_format($value) }}</span>
                </div>
            @endforeach
            @foreach ([
                'آخرین Poll سفارشات' => $status['last_order_poll'],
                'آخرین Poll محصولات' => $status['last_product_poll'],
                'آخرین Backfill شبانه' => $status['last_backfill'],
            ] as $label => $value)
                <div class="flex items-center justify-between py-2.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $fmt($value) }}</span>
                </div>
            @endforeach
        </div>
    </x-common.component-card>

    <x-common.capability-list :available="[
        'این صفحه داده زنده از دیتابیس/هاب می‌خواند؛ معادل دقیق acc:health --json',
        'برای جزئیات خطاهای همگام‌سازی به صفحه «لاگ سیستم» مراجعه کنید',
    ]" :future="[
        'رفرش خودکار دوره‌ای (polling) در همین صفحه بدون رفرش کامل',
        'هشدار/اعلان (مثلاً ایمیل یا تلگرام) هنگام ناسالم شدن سیستم',
        'نمودار روند مصرف صف و تعداد خطا در طول زمان',
    ]" />
</div>
@endsection
