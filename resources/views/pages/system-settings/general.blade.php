@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="تنظیمات کلی" />

<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">مقادیر پیکربندی فعلی سیستم (فقط نمایش؛ ویرایش از این صفحه هنوز پیاده نشده).</p>

    <x-common.component-card title="پیکربندی">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ([
                'نام برنامه' => $config['app_name'],
                'منطقه زمانی' => $config['timezone'],
                'محیط اجرا' => $config['environment'],
                'ضریب تبدیل ارز (به تومان)' => $config['currency_divisor'],
                'آستانه موجودی کم' => $config['low_stock_threshold'],
                'اتصال صف (Queue)' => $config['queue_connection'],
            ] as $label => $value)
                <div class="flex items-center justify-between py-2.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $value }}</span>
                </div>
            @endforeach
        </div>
    </x-common.component-card>

    <x-common.capability-list :available="['نمایش فقط‌خواندنی مقادیر پیکربندی فعلی از فایل‌های config']" :future="[
        'فرم ویرایش نام نمایشی فروشگاه (طبق CLAUDE.md: نام نمایشی قابل‌تنظیم است، جدا از نام برنامه)',
        'ویرایش آستانه موجودی کم محصولات از رابط کاربری',
        'مدیریت نگاشت‌های پیکربندی‌محور (وضعیت‌ها، کانال‌ها، مراکز هزینه) به‌جای مقادیر ثابت در کد',
    ]" :missing="[
        'هیچ جدول/مدل Settings در دیتابیس وجود ندارد — تغییرات این صفحه فعلاً باید در فایل .env یا config انجام شود، نه از UI',
        'برای ویرایش زنده تنظیمات، نیاز به تصمیم درباره محل ذخیره (دیتابیس یا فایل) و کنترل دسترسی است',
    ]" />
</div>
@endsection
