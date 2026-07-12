@extends('layouts.app')

@php
    $columns = [
        ['key' => 'date', 'label' => 'تاریخ', 'sort' => 'date'],
        ['key' => 'description', 'label' => 'شرح'],
        ['key' => 'debit', 'label' => 'بدهکار (کاهش بدهی)', 'sort' => 'debit'],
        ['key' => 'credit', 'label' => 'بستانکار (افزایش بدهی)', 'sort' => 'credit'],
        // Deliberately not sortable: it is a running balance, and it only means
        // anything read in date order.
        ['key' => 'balance_after', 'label' => 'مانده پس از تراکنش'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="'تراکنش‌های مالی — '.$supplier->name" parentLabel="تامین‌کننده‌ها" :parentUrl="route('suppliers.index')" />

<div class="mb-4">
    <x-nav.tabs :tabs="$tabs" param="tab" active="transactions" />
</div>

<div class="space-y-4">
    <x-common.component-card title="مانده حساب پرداختنی">
        <p class="text-sm font-medium {{ $balance < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">
            {{ number_format($balance) }} تومان
        </p>
    </x-common.component-card>

    <x-common.component-card title="تراکنش‌های مالی">
        <x-tables.pro-table
            :columns="$columns"
            :paginator="$transactions"
            :query="$query"
            empty-message="هنوز تراکنشی برای این تامین‌کننده ثبت نشده است"
            search-value="{{ $filters['search'] ?? '' }}"
            search-placeholder="جستجوی شرح تراکنش"
            :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
            storage-key="suppliers.transactions.visibleColumns"
        >
            @foreach ($transactions as $line)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td x-show="visible.date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($line->entry->entry_date) }}</td>
                    <td x-show="visible.description" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">{{ $line->entry->description }}</td>
                    <x-tables.num x-show="visible.debit" class="px-5 py-3 sm:px-6" :value="$line->debit > 0 ? $line->debit : null" tone="positive" />
                    <x-tables.num x-show="visible.credit" class="px-5 py-3 sm:px-6" :value="$line->credit > 0 ? $line->credit : null" tone="negative" />
                    <x-tables.num x-show="visible.balance_after" class="whitespace-nowrap px-5 py-3 sm:px-6" :value="$line->balance_after" />
                </tr>
            @endforeach
        </x-tables.pro-table>
    </x-common.component-card>
</div>
@endsection
