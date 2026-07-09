@extends('layouts.app')

@php
    $eventStatusLabels = [
        'received' => 'دریافت‌شده', 'processing' => 'در حال پردازش', 'done' => 'موفق',
        'failed' => 'ناموفق (در انتظار تلاش مجدد)', 'dead' => 'مرده (نیازمند بررسی)',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت وبهوک‌ها و API" />

<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">این سیستم فقط وبهوک ورودی از هاب را دریافت می‌کند؛ مدیریت اندپوینت وبهوک در سمت هاب انجام می‌شود.</p>

    <x-common.component-card title="اتصال هاب">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">آدرس پایه هاب</span>
                <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $hub['base_url'] }}</span>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">کلید API هاب</span>
                <x-ui.badge :color="$hub['api_key_configured'] ? 'success' : 'error'">{{ $hub['api_key_configured'] ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}</x-ui.badge>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">کلید امضای وبهوک</span>
                <x-ui.badge :color="$hub['webhook_secret_configured'] ? 'success' : 'error'">{{ $hub['webhook_secret_configured'] ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}</x-ui.badge>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">حداکثر تلاش پردازش وبهوک</span>
                <span class="font-medium text-gray-800 dark:text-white/90">{{ $hub['webhook_max_attempts'] }}</span>
            </div>
            <div class="flex items-center justify-between py-2.5 text-sm">
                <span class="text-gray-500 dark:text-gray-400">اندپوینت دریافت وبهوک (سمت این سیستم)</span>
                <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $hub['webhook_endpoint'] }}</span>
            </div>
        </div>
    </x-common.component-card>

    <x-common.component-card title="وضعیت رویدادهای وبهوک دریافتی">
        <div class="flex flex-wrap gap-2">
            @foreach ($webhookEventCounts as $status => $count)
                <x-ui.badge color="light" size="sm">{{ $eventStatusLabels[$status] ?? $status }}: {{ number_format($count) }}</x-ui.badge>
            @endforeach
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">برای بررسی و تلاش مجدد رویدادهای ناموفق به <a href="{{ route('tools.system-logs') }}" class="underline">صفحه لاگ سیستم</a> مراجعه کنید.</p>
    </x-common.component-card>

    <x-common.capability-list :available="[
        'نمایش وضعیت پیکربندی اتصال به هاب (base URL، کلید API، کلید امضای وبهوک)',
        'شمارش رویدادهای وبهوک به تفکیک وضعیت',
        'تلاش مجدد وبهوک‌های ناموفق از صفحه لاگ سیستم',
    ]" :future="[
        'مدیریت کلیدهای API از رابط کاربری (تولید/چرخش کلید) به‌جای ویرایش دستی .env',
        'ثبت و مدیریت اندپوینت‌های وبهوک خروجی، در صورتی که این سیستم در آینده به سرویس دیگری وبهوک ارسال کند',
        'نمایش تاریخچه کامل درخواست‌های API به هاب (نرخ موفقیت، تأخیر)',
    ]" :missing="[
        'این اپلیکیشن هیچ API عمومی یا وبهوک خروجی ندارد؛ صرفاً مصرف‌کننده API هاب و گیرنده وبهوک آن است — طبق CLAUDE.md، داده مالی حساس هرگز نباید از طریق API عمومی یا وبهوک منتشر شود',
        'چرخش/تغییر کلید امضای وبهوک از UI نیازمند هماهنگی با ثبت اندپوینت در سمت هاب است (تصمیم معماری sync)',
    ]" />
</div>
@endsection
