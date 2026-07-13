@extends('layouts.app')

@php
    $columns = [
        ['key' => 'date', 'label' => 'تاریخ', 'sort' => 'date'],
        ['key' => 'description', 'label' => 'شرح'],
        ['key' => 'party', 'label' => 'طرف حساب'],
        ['key' => 'notes', 'label' => 'یادداشت'],
        ['key' => 'debit', 'label' => 'بدهکار (واریز)', 'sort' => 'debit'],
        ['key' => 'credit', 'label' => 'بستانکار (برداشت)', 'sort' => 'credit'],
        // Deliberately not sortable: it is a running balance, and it only means
        // anything read in date order.
        ['key' => 'balance_after', 'label' => 'مانده پس از تراکنش'],
    ];

    $filterLabels = ['date_from' => 'از تاریخ', 'date_to' => 'تا تاریخ'];
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
            :query="$query"
            :filterLabels="$filterLabels"
            empty-message="هنوز تراکنشی برای این حساب ثبت نشده است"
            search-value="{{ $filters['search'] ?? '' }}"
            search-placeholder="جستجوی شرح یا طرف حساب"
            with-date-range
            date-from-value="{{ $filters['date_from'] ?? null }}"
            date-to-value="{{ $filters['date_to'] ?? null }}"
            :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
            storage-key="bankAccounts.show.visibleColumns"
        >
            @foreach ($transactions as $line)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td x-show="visible.date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($line->entry->entry_date) }}</td>
                    <td x-show="visible.description" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">
                        {{-- A transfer posts ONE entry that lands in both accounts' ledgers, so
                             this row exists on both sides. Linking it back to the operation is
                             what lets a reader of either ledger see the whole movement. --}}
                        @if ($operationUrl = \App\Support\Design\LedgerSourceLink::for($line->entry->source))
                            <a href="{{ $operationUrl }}" class="font-medium text-brand-500 hover:underline">{{ $line->entry->description }}</a>
                        @else
                            {{ $line->entry->description }}
                        @endif
                    </td>
                    <td x-show="visible.party" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">
                        @if ($line->party?->hasRole('customer'))
                            <a href="{{ route('customers.show', $line->party) }}" class="font-medium text-brand-500 hover:underline">{{ $line->party->name }}</a>
                        @else
                            {{ $line->party->name ?? '—' }}
                        @endif
                    </td>
                    <td x-show="visible.notes" class="px-5 py-3 sm:px-6">
                        @if ($line->entry->source instanceof \App\Domain\Receivables\Models\PartyPayment)
                            @include('pages.suppliers.partials.note-edit-control', ['payment' => $line->entry->source])
                        @elseif ($line->memo)
                            {{-- The line's own memo, not the entry's. On a transfer the two sides
                                 carry OPPOSITE memos ("انتقال به …" / "انتقال از …"), so each
                                 ledger reads in its own direction instead of showing both
                                 accounts the same sentence. --}}
                            <span class="text-gray-600 dark:text-gray-400">{{ $line->memo }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- A zero debit/credit is a non-entry on this side of the ledger, so it
                         reads as '—' rather than a misleading 0. --}}
 <x-tables.num x-show="visible.debit" class="px-5 py-3 sm:px-6" :value="$line->debit > 0 ? $line->debit : null" tone="positive" />
                    <x-tables.num x-show="visible.credit" class="px-5 py-3 sm:px-6" :value="$line->credit > 0 ? $line->credit : null" tone="negative" />
 <x-tables.num x-show="visible.balance_after" class="whitespace-nowrap px-5 py-3 sm:px-6" :value="$line->balance_after" />
                </tr>
            @endforeach
        </x-tables.pro-table>
    </x-common.component-card>
</div>
@endsection
