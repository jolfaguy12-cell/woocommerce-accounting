@extends('layouts.app')

{{--
    «هزینه‌ها».

    «وضعیت تسویه» is the column that did not exist. An expense recorded as unpaid is
    a real debt on 2000 from the day it is entered, and until this page there was no
    way to see which of them the company still owed — or to pay one without recording
    a SECOND expense, which recognises the same cost twice.

    The status is read from the ledger on every render, never from a stored flag: a
    reversed settlement puts the money straight back on the bill, and nothing has to
    remember to un-flag anything.
--}}
@php
    $selectClass = 'h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="هزینه‌ها" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-end gap-2">
        <a href="{{ route('expenses.reimbursements.create') }}"
           class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-4 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
            بازپرداخت هزینه کارمند/شریک
        </a>
        <a href="{{ route('fast-forms') }}"
           class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
            ثبت هزینه
        </a>
    </div>

    <x-common.component-card title="هزینه‌ها"
        desc="«وضعیت تسویه» از دفتر روزنامه خوانده می‌شود؛ هیچ ستون «پرداخت‌شده»‌ای ذخیره نمی‌شود.">

        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
            <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="جستجوی شرح یا طرف حساب…"
                class="h-10 w-full max-w-xs rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

            <select name="funding_source" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه منابع پرداخت</option>
                @foreach ($fundingSources as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['funding_source'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <select name="settlement" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه وضعیت‌ها</option>
                @foreach ($settlementStatuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['settlement'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            <button type="submit" class="inline-flex h-10 items-center rounded-lg border border-gray-300 px-4 text-theme-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                اعمال
            </button>
        </form>

        @if ($rows->isEmpty())
            <x-states.state variant="no-results"
                title="هزینه‌ای یافت نشد"
                message="فیلترها را تغییر دهید یا از «ثبت هزینه» یک هزینه جدید ثبت کنید." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">تاریخ</th>
                            <th class="px-4 py-3 font-medium">شرح</th>
                            <th class="px-4 py-3 font-medium">دسته</th>
                            <th class="px-4 py-3 font-medium">منبع پرداخت</th>
                            <th class="px-4 py-3 font-medium">پرداخت‌کننده</th>
                            <th class="px-4 py-3 font-medium">مبلغ</th>
                            <th class="px-4 py-3 font-medium">مانده</th>
                            <th class="px-4 py-3 font-medium">وضعیت تسویه</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            @php($expense = $row['expense'])
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <x-tables.ltr :value="$row['date_fa']" />
                                <td class="px-4 py-3">
                                    <a href="{{ route('expenses.show', $expense) }}"
                                       class="text-theme-sm font-medium text-brand-500 hover:underline">
                                        {{ $expense->description }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $expense->category?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $expense->fundingSource()->label() }}</td>
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">
                                    {{ $expense->fundedByParty?->name ?? ($expense->bankAccount?->name ?? '—') }}
                                </td>
                                <x-tables.num :value="(int) $expense->amount" type="toman" />
                                <x-tables.num :value="$row['remaining']" type="toman" zero="—" tone="muted" />
                                <td class="px-4 py-3">
                                    <x-ui.status :status="$row['status']->badge()" :label="$row['status']->label()" />
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row['remaining'] > 0)
                                        <a href="{{ route('expenses.show', $expense) }}"
                                           class="text-theme-sm text-brand-500 hover:underline">تسویه</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $expenses->links() }}</div>
        @endif
    </x-common.component-card>
</div>
@endsection
