@extends('layouts.app')

@php
    $roleLabels = ['admin' => 'مدیر', 'accountant' => 'حسابدار', 'warehouse' => 'انباردار', 'partner_viewer' => 'شریک (فقط گزارش)'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت نقش‌ها" />

<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">نقش‌های موجود در سیستم (Spatie Permission) و تعداد کاربران هر نقش.</p>

    <x-common.component-card title="نقش‌ها">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 font-normal">نقش</th>
                        <th class="py-2 font-normal">تعداد کاربران</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roles as $r)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">{{ $roleLabels[$r['name']] ?? $r['name'] }} <span class="text-gray-500 dark:text-gray-400">({{ $r['name'] }})</span></td>
                            <td class="py-2"><x-ui.badge color="light" size="sm">{{ number_format($r['users_count']) }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            از مجموع {{ number_format($totalUsers) }} کاربر. برای تغییر نقش یک کاربر یا ساخت کاربر جدید به <a href="{{ route('users.index') }}" class="underline">صفحه کاربران</a> مراجعه کنید.
        </p>
    </x-common.component-card>

    <x-common.capability-list :available="[
        'نمایش نقش‌های موجود و تعداد کاربران هر نقش (از spatie/laravel-permission، از پیش نصب‌شده)',
        'اختصاص نقش به هر کاربر هم‌اکنون در صفحه کاربران (/users) پیاده‌سازی شده است',
    ]" :future="[
        'تعریف نقش جدید یا حذف نقش از رابط کاربری',
        'مدیریت مجوزهای ریزدانه (permissions) به‌جای فقط نقش‌های ثابت فعلی — در حال حاضر مجوز مشخصی تعریف نشده و کنترل دسترسی فقط بر اساس نام نقش در route است',
        'مشاهده اینکه هر نقش دقیقاً به کدام صفحات/اکشن‌ها دسترسی دارد',
    ]" :missing="[
        'هیچ رکورد Permission (مجوز ریزدانه) در سیستم تعریف نشده — کنترل دسترسی فعلی صرفاً role:admin|accountant|... در routes/web.php است، نه permission-based',
        'حذف/ساخت نقش از UI نیازمند تصمیم درباره ریسک قفل‌شدن آخرین ادمین (مشابه محافظتی که برای حذف آخرین ادمین در UserController وجود دارد)',
    ]" />
</div>
@endsection
