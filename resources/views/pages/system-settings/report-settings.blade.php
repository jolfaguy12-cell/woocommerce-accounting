@extends('layouts.app')

@php
    $periodStatusLabels = ['open' => 'باز', 'soft_closed' => 'بسته موقت', 'locked' => 'قفل‌شده'];
    $reportStateLabels = ['draft' => 'پیش‌نویس', 'needs_review' => 'نیازمند بازبینی', 'final' => 'نهایی‌شده', 'adjusted' => 'اصلاح‌شده'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="تنظیمات گزارشات" />

<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">هنوز مدل مستقلی برای «تنظیمات گزارش» وجود ندارد؛ نزدیک‌ترین داده واقعی، وضعیت قفل دوره‌های حسابداری است.</p>

    <x-common.component-card title="دوره‌های اخیر">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 font-normal">دوره</th>
                        <th class="py-2 font-normal">وضعیت</th>
                        <th class="py-2 font-normal">زمان قفل</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($periods as $p)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">{{ $p->jalali_period }}</td>
                            <td class="py-2"><x-ui.badge :color="$p->status === 'locked' ? 'success' : 'light'" size="sm">{{ $periodStatusLabels[$p->status] ?? $p->status }}</x-ui.badge></td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">{{ $p->locked_at ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($p->locked_at)) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-common.component-card>

    <x-common.component-card title="تعداد گزارش‌ها بر اساس وضعیت">
        <div class="flex flex-wrap gap-2">
            @foreach ($reportCounts as $state => $count)
                <x-ui.badge color="light" size="sm">{{ $reportStateLabels[$state] ?? $state }}: {{ number_format($count) }}</x-ui.badge>
            @endforeach
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">برای مدیریت کامل گزارش‌ها (نهایی‌سازی، تعدیل) به <a href="{{ route('reports.index') }}" class="underline">صفحه گزارشات</a> مراجعه کنید.</p>
    </x-common.component-card>

    <x-common.capability-list :available="[
        'نمایش وضعیت قفل دوره‌های حسابداری (accounting_periods)',
        'نمایش تعداد گزارش‌های شرکا بر اساس وضعیت (پیش‌نویس/نهایی/اصلاح‌شده)',
    ]" :future="[
        'تعریف قالب/آستانه‌های گزارش از رابط کاربری (مثلاً چه مواردی باید در گزارش شریک نمایش داده شود)',
        'زمان‌بندی تولید خودکار گزارش‌های دوره‌ای و اعلان به مدیر',
        'قفل/باز کردن دستی یک دوره از همین صفحه (در حال حاضر فقط از طریق نهایی‌سازی گزارش انجام می‌شود)',
    ]" :missing="[
        'هیچ مدل «ReportSetting» یا جدول تنظیمات گزارش وجود ندارد — نیاز به تصمیم درباره اینکه چه پارامترهایی باید قابل‌تنظیم باشند',
        'قفل/باز کردن دستی دوره از UI نیازمند تصمیم حساس حسابداری است (طبق CLAUDE.md نیازمند تأیید کاربر)',
    ]" />
</div>
@endsection
