@extends('layouts.app')

@php
    $columns = [
        ['key' => 'invoice_no', 'label' => 'شماره فاکتور', 'sort' => 'invoice_no'],
        ['key' => 'supplier_name', 'label' => 'تامین‌کننده', 'sort' => 'supplier_name'],
        ['key' => 'item_name', 'label' => 'کالا', 'sort' => 'item_name'],
        ['key' => 'ordered', 'label' => 'سفارش‌شده'],
        ['key' => 'received', 'label' => 'دریافت‌شده'],
        ['key' => 'outstanding', 'label' => 'باقی‌مانده', 'sort' => 'outstanding_qty'],
        ['key' => 'age', 'label' => 'مدت تأخیر', 'sort' => 'age_days'],
        ['key' => 'package', 'label' => 'بسته‌بندی'],
        ['key' => 'notes', 'label' => 'یادداشت'],
        ['key' => 'actions', 'label' => 'عملیات'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="کالاهای دریافت‌نشده" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$rows"
    :query="$query"
    empty-message="هیچ کالای معوقی وجود ندارد — همه فاکتورها به‌موقع دریافت شده‌اند"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی کالا / شماره فاکتور / تامین‌کننده"
    storage-key="unreceivedGoods.visibleColumns"
>
    @foreach ($rows as $row)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <x-tables.ltr x-show="visible.invoice_no" class="px-5 sm:px-6" :value="$row->invoice_no" tone="muted" />
            <td x-show="visible.supplier_name" class="px-5 py-3 sm:px-6">
                <a href="{{ route('suppliers.show', $row->supplier_party_id) }}" class="text-brand-500 hover:underline">{{ $row->supplier_name }}</a>
            </td>
            <td x-show="visible.item_name" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">{{ $row->item_name }}</td>
            <x-tables.num x-show="visible.ordered" class="px-5 sm:px-6" :value="$row->qty" tone="muted" />
            <x-tables.num x-show="visible.received" class="px-5 sm:px-6" :value="$row->received_qty" tone="muted" />
            <x-tables.num x-show="visible.outstanding" class="px-5 font-medium sm:px-6" :value="$row->outstanding_qty" tone="negative" />
            <td x-show="visible.age" class="px-5 py-3 sm:px-6">
                <x-ui.badge :color="$row->age_days >= 10 ? 'error' : 'warning'" size="sm">{{ number_format($row->age_days) }} روز</x-ui.badge>
            </td>
            <td x-show="visible.package" class="px-5 py-3 text-gray-500 sm:px-6 dark:text-gray-400">
                @php $packages = $row->receiptLines->filter(fn ($l) => $l->package_count)->map(fn ($l) => number_format($l->package_count).' '.($l->package_label ?? 'بسته')); @endphp
                {{ $packages->isNotEmpty() ? $packages->implode('، ') : '—' }}
            </td>
            <td x-show="visible.notes" class="px-5 py-3 text-gray-500 sm:px-6 dark:text-gray-400">{{ $row->note ?? '—' }}</td>
            <td x-show="visible.actions" class="px-5 py-3 sm:px-6">
                <a href="{{ route('purchases.show', $row->purchase_invoice_id) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">مشاهده فاکتور</a>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
