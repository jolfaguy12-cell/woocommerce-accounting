@extends('layouts.app')

@php
    $fmt = fn ($iso) => $iso ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($iso)) : '—';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="لاگ سیستم" />

@if (session('success'))
    <div class="mb-4"><x-ui.alert variant="success" :message="session('success')" /></div>
@endif

<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">خطاهای همگام‌سازی (وبهوک‌ها) و تاریخچه اجراهای Sync؛ معادل acc:sync:errors</p>

    <x-common.component-card title="وبهوک‌های ناموفق/مرده ({{ count($webhookEvents) }})">
        @if (count($webhookEvents))
            <form method="POST" action="{{ route('tools.system-logs.retry') }}" class="mb-3">
                @csrf
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">تلاش مجدد همه</button>
            </form>
        @endif

        @if (count($webhookEvents) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">موردی یافت نشد.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="py-2 font-normal">نوع رویداد</th>
                            <th class="py-2 font-normal">وضعیت</th>
                            <th class="py-2 font-normal">تلاش‌ها</th>
                            <th class="py-2 font-normal">خطا</th>
                            <th class="py-2 font-normal">زمان</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($webhookEvents as $e)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="py-2 text-gray-800 dark:text-white/90">{{ $e->event_type }}</td>
                                <td class="py-2"><x-ui.badge :color="$e->status === 'dead' ? 'error' : 'light'" size="sm">{{ $e->status }}</x-ui.badge></td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $e->attempts }}</td>
                                <td class="max-w-xs truncate py-2 text-gray-500 dark:text-gray-400" title="{{ $e->last_error }}">{{ $e->last_error ?? '—' }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $fmt($e->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-common.component-card>

    <x-common.component-card title="آخرین اجراهای Sync ({{ count($syncRuns) }})">
        @if (count($syncRuns) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">موردی یافت نشد.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="py-2 font-normal">نوع</th>
                            <th class="py-2 font-normal">وضعیت</th>
                            <th class="py-2 font-normal">شروع</th>
                            <th class="py-2 font-normal">پایان</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($syncRuns as $r)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="py-2 text-gray-800 dark:text-white/90">{{ $r->type }}</td>
                                <td class="py-2"><x-ui.badge :color="$r->status === 'done' ? 'success' : ($r->status === 'failed' ? 'error' : 'light')" size="sm">{{ $r->status }}</x-ui.badge></td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $fmt($r->started_at) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $fmt($r->finished_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-common.component-card>

    <x-common.capability-list :available="[
        'مشاهده و تلاش مجدد وبهوک‌های ناموفق/مرده (معادل acc:sync:errors --retry)',
        'تاریخچه اجراهای Poll/Backfill از جدول sync_runs',
    ]" :future="[
        'صفحه‌بندی و جست‌وجو در تاریخچه به‌جای محدودیت ثابت ۵۰/۲۰ ردیف',
        'مشاهده جزئیات کامل payload هر رویداد وبهوک (در پنجره مجزا)',
        'فیلتر بر اساس بازه زمانی و نوع رویداد',
    ]" :missing="[
        'نمایش لاگ عمومی اپلیکیشن (storage/logs/laravel.log) در رابط کاربری هنوز پیاده نشده — طبق سیاست پروژه، لاگ عمومی نباید داده حساس مالی نمایش دهد؛ نیازمند طراحی لاگ حسابرسی محافظت‌شده (audit log) به‌جای نمایش مستقیم فایل لاگ',
        'جدول activity_log (spatie/laravel-activitylog) نصب شده اما هنوز به هیچ مدلی متصل نیست — نیازمند تصمیم درباره اینکه کدام تغییرات مالی باید حسابرسی شوند',
    ]" />
</div>
@endsection
