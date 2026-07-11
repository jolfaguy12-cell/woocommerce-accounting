@extends('layouts.app')

@php
    $columns = [
        ['key' => 'date', 'label' => 'تاریخ'],
        ['key' => 'description', 'label' => 'شرح'],
        ['key' => 'party', 'label' => 'طرف حساب'],
        ['key' => 'debit', 'label' => 'بدهکار (واریز)', 'align' => 'center'],
        ['key' => 'credit', 'label' => 'بستانکار (برداشت)', 'align' => 'center'],
        ['key' => 'balance_after', 'label' => 'مانده پس از تراکنش', 'align' => 'center'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$bankAccount->name" parentLabel="حساب‌ها" :parentUrl="route('bank-accounts.index')" />

<div class="mb-4 flex justify-end">
    <a href="{{ route('deposits.index', ['bank_account_id' => $bankAccount->id]) }}" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
        واریزی‌های زیبال این حساب
    </a>
</div>

<div class="space-y-4">
    <x-common.component-card :title="$bankAccount->name">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">نام بانک</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $bankAccount->bank_name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره کارت</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $bankAccount->card_number ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره شبا</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $bankAccount->iban ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">موجودی فعلی</p>
                <p class="mt-1 text-sm font-medium {{ $balance < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">{{ number_format($balance) }} تومان</p>
            </div>
        </div>
    </x-common.component-card>

    <x-common.component-card title="تراکنش‌های حساب">
        <x-tables.pro-table
            :columns="$columns"
            :paginator="$transactions"
            empty-message="تراکنشی برای این حساب یافت نشد"
            search-value="{{ $filters['search'] ?? '' }}"
            search-placeholder="جستجوی شرح یا طرف حساب"
            with-date-range
            date-from-value="{{ $filters['date_from'] ?? null }}"
            date-to-value="{{ $filters['date_to'] ?? null }}"
            :clear-filters-route="array_filter($filters) ? route('bank-accounts.show', $bankAccount) : null"
            storage-key="bankAccounts.show.visibleColumns"
        >
            @foreach ($transactions as $line)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td x-show="visible.date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($line->entry->entry_date) }}</td>
                    <td x-show="visible.description" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">{{ $line->entry->description }}</td>
                    <td x-show="visible.party" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">
                        @if ($line->party?->type === 'customer')
                            <a href="{{ route('customers.show', $line->party) }}" class="font-medium text-brand-500 hover:underline">{{ $line->party->name }}</a>
                        @else
                            {{ $line->party->name ?? '—' }}
                        @endif
                    </td>
                    <td x-show="visible.debit" class="whitespace-nowrap px-5 py-3 text-center text-success-600 sm:px-6 dark:text-success-400" dir="ltr">{{ $line->debit > 0 ? number_format($line->debit) : '—' }}</td>
                    <td x-show="visible.credit" class="whitespace-nowrap px-5 py-3 text-center text-error-500 sm:px-6" dir="ltr">{{ $line->credit > 0 ? number_format($line->credit) : '—' }}</td>
                    <td x-show="visible.balance_after" class="whitespace-nowrap px-5 py-3 text-center text-gray-800 sm:px-6 dark:text-white/90" dir="ltr">{{ number_format($line->balance_after) }}</td>
                </tr>
            @endforeach
        </x-tables.pro-table>
    </x-common.component-card>
</div>
@endsection
