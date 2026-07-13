@extends('layouts.app')

@php
    $eventStatusLabels = [
        'received' => 'دریافت‌شده', 'processing' => 'در حال پردازش', 'done' => 'موفق',
        'failed' => 'ناموفق (در انتظار تلاش مجدد)', 'dead' => 'مرده (نیازمند بررسی)',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت وبهوک‌ها و API" />

@if (session('success'))
    <x-ui.alert variant="success" :message="session('success')" class="mb-4" />
@endif
@if ($errors->has('bot_token'))
    <x-ui.alert variant="error" :message="$errors->first('bot_token')" class="mb-4" />
@endif

<x-nav.tabs :tabs="['hub' => 'اتصال هاب', 'telegram' => 'تلگرام']" :panels="true">
<div x-show="tab === 'hub'" class="space-y-4">
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

<div x-show="tab === 'telegram'" class="space-y-4">
    <x-common.component-card title="ربات تلگرام هشدارها">
        <div class="flex items-center justify-between border-b border-gray-100 py-2.5 text-sm dark:border-gray-800">
            <span class="text-gray-500 dark:text-gray-400">کلید ربات (Bot Token)</span>
            <div class="flex items-center gap-2">
                <x-ui.badge :color="$telegram['configured'] ? 'success' : 'error'">{{ $telegram['configured'] ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}</x-ui.badge>
                @if ($telegram['masked'])
                    <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $telegram['masked'] }}</span>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('setting.api-webhook-management.telegram.update') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-[280px] flex-1">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">کلید جدید ربات</label>
                <input type="password" name="bot_token" autocomplete="off" placeholder="123456789:AA..." dir="ltr"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </div>
            <button type="submit" class="h-11 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </form>

        @if ($telegram['configured'])
            <form method="POST" action="{{ route('setting.api-webhook-management.telegram.reset') }}" onsubmit="return confirm('کلید ربات تلگرام حذف شود؟ ارسال هشدارها متوقف می‌شود.')" class="mt-3">
                @csrf
                <button type="submit" class="text-xs text-error-500 hover:underline">حذف کلید</button>
            </form>
        @endif

        <p class="mt-3 text-xs text-gray-400">کلید هرگز به‌صورت کامل نمایش داده نمی‌شود. برای دریافت هشدار در تلگرام، هر کاربر باید شناسه چت خود را در صفحه «کاربران» ثبت کند.</p>
    </x-common.component-card>
</div>
</x-nav.tabs>
@endsection
