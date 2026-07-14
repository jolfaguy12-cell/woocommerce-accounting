@extends('layouts.app')

{{--
    «کارکنان» — every employee, with the three balances that decide whether anyone
    needs to act: what we owe them in salary, what they hold as an advance, and what
    they have laid out for the company and not been paid back.

    They are three columns, never one: an employee owed 12,000,000 in salary who is
    holding a 2,000,000 advance is not "owed 10,000,000". The salary is due on
    payday; the advance comes back out of the next payroll run.
--}}

@section('content')
<x-common.page-breadcrumb pageTitle="کارکنان" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-end gap-2">
        <a href="{{ route('payroll.create') }}"
           class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
            ثبت حقوق دوره
        </a>
        <a href="{{ route('payroll.index') }}"
           class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-4 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
            لیست‌های حقوق
        </a>
    </div>

    @if ($rows->isEmpty())
        <x-states.state variant="empty"
            title="هیچ طرف حسابی نقش «کارمند» ندارد"
            message="کارمند، یک طرف حساب با نقش «کارمند» است. از صفحهٔ طرف حساب‌ها، تب «مدیریت نقش‌ها»، این نقش را فعال کنید." />
    @else
        <x-common.component-card title="کارکنان"
            desc="همه مبالغ از دفتر روزنامه خوانده می‌شوند؛ هیچ مانده‌ای ذخیره نشده است.">

            <form method="GET" class="mb-4">
                <input type="search" name="search" value="{{ $search }}" placeholder="جستجوی نام یا شماره تماس…"
                    class="h-10 w-full max-w-sm rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">نام</th>
                            <th class="px-4 py-3 font-medium">سمت</th>
                            <th class="px-4 py-3 font-medium">حقوق</th>
                            <th class="px-4 py-3 font-medium">مانده حقوق</th>
                            <th class="px-4 py-3 font-medium">مساعده</th>
                            <th class="px-4 py-3 font-medium">هزینه پرداخت‌شده توسط کارمند</th>
                            <th class="px-4 py-3 font-medium">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-4 py-3">
                                    <a href="{{ route('employees.show', $row['party']) }}"
                                       class="text-theme-sm font-medium text-brand-500 hover:underline">
                                        {{ $row['party']->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">
                                    {{ $row['employee']->job_title ?? '—' }}
                                </td>
                                <x-tables.num :value="$row['salary']" type="toman" tone="muted" />
                                <x-tables.num :value="$row['salary_balance']" type="toman" />
                                <x-tables.num :value="$row['advances']" type="toman" />
                                <x-tables.num :value="$row['employee_paid_expenses']" type="toman" />
                                <td class="px-4 py-3">
                                    <x-ui.status :status="$row['employee']->is_active ? 'completed' : 'archived'"
                                        :label="$row['employee']->is_active ? 'فعال' : 'غیرفعال'" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-common.component-card>
    @endif
</div>
@endsection
