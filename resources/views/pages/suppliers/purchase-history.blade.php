@extends('layouts.app')

@php
    $columns = [
        ['key' => 'invoice_date', 'label' => 'تاریخ فاکتور', 'sort' => 'invoice_date'],
        ['key' => 'item', 'label' => 'قلم / کالا'],
        ['key' => 'qty', 'label' => 'تعداد', 'sort' => 'qty'],
        ['key' => 'unit_price', 'label' => 'قیمت واحد', 'sort' => 'unit_price'],
        ['key' => 'landed_unit_cost', 'label' => 'بهای تمام‌شده واحد', 'sort' => 'landed_unit_cost'],
        ['key' => 'status', 'label' => 'وضعیت فاکتور'],
    ];

    $statusLabels = ['draft' => 'ثبت‌شده', 'partial' => 'دریافت جزئی', 'received' => 'دریافت‌شده', 'cancelled' => 'لغوشده'];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="'سابقه خرید — '.$supplier->name" parentLabel="تامین‌کننده‌ها" :parentUrl="route('suppliers.index')" />

<div class="mb-4">
    <x-nav.tabs :tabs="$tabs" param="tab" active="purchases" />
</div>

<x-tables.pro-table
    :columns="$columns"
    :paginator="$purchases"
    :query="$query"
    empty-message="هنوز خریدی از این تامین‌کننده ثبت نشده است"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی نام کالا"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="suppliers.purchaseHistory.visibleColumns"
>
    @foreach ($purchases as $line)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <x-tables.ltr x-show="visible.invoice_date" class="px-5 sm:px-6" :value="\Morilog\Jalali\Jalalian::fromCarbon($line->invoice->invoice_date)->format('Y/m/d')" />
            <td x-show="visible.item" class="px-5 py-3 sm:px-6">
                <a href="{{ route('purchases.show', $line->invoice) }}" class="text-brand-500 hover:underline">{{ $line->costItem->name }}</a>
            </td>
            <x-tables.num x-show="visible.qty" class="px-5 sm:px-6" :value="$line->qty" tone="muted" />
            <x-tables.num x-show="visible.unit_price" class="px-5 sm:px-6" :value="$line->unit_price" type="toman" tone="muted" />
            <x-tables.num x-show="visible.landed_unit_cost" class="px-5 sm:px-6" :value="$line->landed_unit_cost" type="toman" />
            <td x-show="visible.status" class="px-5 py-3 sm:px-6">
                <x-ui.badge :color="$line->invoice->status === 'received' ? 'success' : ($line->invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                    {{ $statusLabels[$line->invoice->status] ?? $line->invoice->status }}
                </x-ui.badge>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
