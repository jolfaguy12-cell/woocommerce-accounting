@extends('layouts.app')

@php
    $columns = [
        ['key' => 'name', 'label' => 'نام تامین‌کننده', 'sort' => 'name'],
        ['key' => 'contact', 'label' => 'تماس'],
        ['key' => 'bank_account', 'label' => 'شماره حساب'],
        ['key' => 'invoices_count', 'label' => 'تعداد فاکتور', 'sort' => 'invoices_count'],
        ['key' => 'payable_balance', 'label' => 'مانده حساب', 'sort' => 'payable_balance'],
        ['key' => 'actions', 'label' => 'عملیات'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="تامین‌کننده‌ها" />

@if (session('success'))
    <x-ui.alert variant="success" :message="session('success')" class="mb-4" />
@endif

<x-tables.pro-table
    :columns="$columns"
    :paginator="$suppliers"
    :query="$query"
    empty-message="هنوز تامین‌کننده‌ای ثبت نشده است"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی نام، فروشگاه یا تلفن"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="suppliers.visibleColumns"
>
    <x-slot:actions>
        <button type="button" @click="$dispatch('open-supplier-modal', null)" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + تامین‌کننده جدید
        </button>
    </x-slot:actions>

    @foreach ($suppliers as $supplier)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <td x-show="visible.name" class="px-5 py-3 sm:px-6">
                <a href="{{ route('suppliers.show', $supplier) }}" class="font-medium text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90">{{ $supplier->name }}</a>
                @if ($supplier->shop_name)
                    <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $supplier->shop_name }}</span>
                @endif
            </td>
            <td x-show="visible.contact" class="px-5 py-3 sm:px-6">
                <x-tables.ltr :value="$supplier->phone" :cell="false" tone="muted" class="block" />
                <x-tables.ltr :value="$supplier->email" :cell="false" tone="subtle" class="block text-xs" />
            </td>
            <x-tables.ltr x-show="visible.bank_account" class="px-5 sm:px-6" :value="$supplier->bank_account_number" tone="muted" />
            <td x-show="visible.invoices_count" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ number_format($supplier->invoices_count) }}</td>
            <td x-show="visible.payable_balance" class="px-5 py-3 sm:px-6">
                <x-tables.num :value="$supplier->payable_balance" type="toman" :signed="true" :cell="false" />
                @if ($supplier->payable_balance > 0)
                    <span class="mt-0.5 block text-theme-xs text-gray-400">بدهکار ما</span>
                @elseif ($supplier->payable_balance < 0)
                    <span class="mt-0.5 block text-theme-xs text-gray-400">طلبکار ما</span>
                @endif
            </td>
            <td x-show="visible.actions" class="px-5 py-3 sm:px-6">
                <div class="flex items-center gap-2">
                    <a href="{{ route('suppliers.show', $supplier) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">مشاهده</a>
                    <button type="button"
                        @click="$dispatch('open-supplier-modal', @js($supplier->only(['id', 'name', 'shop_name', 'phone', 'email', 'address', 'bank_account_number', 'notes'])))"
                        class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        ویرایش
                    </button>
                </div>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>

@include('pages.suppliers.partials.edit-modal')
@endsection
