@extends('layouts.app')

@php
    $columns = [
        ['key' => 'invoice_date', 'label' => 'تاریخ', 'sort' => 'invoice_date'],
        ['key' => 'supplier', 'label' => 'تامین‌کننده'],
        ['key' => 'invoice_no', 'label' => 'شماره فاکتور', 'sort' => 'invoice_no'],
        ['key' => 'qty', 'label' => 'تعداد'],
        ['key' => 'total', 'label' => 'جمع کل', 'sort' => 'total'],
        ['key' => 'status', 'label' => 'وضعیت', 'sort' => 'status'],
        ['key' => 'attachments', 'label' => 'پیوست'],
        ['key' => 'actions', 'label' => 'عملیات'],
    ];

    $filterLabels = ['supplier_party_id' => 'تامین‌کننده', 'status' => 'وضعیت'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت خرید" />

@if (session('success'))
    <x-ui.alert variant="success" :message="session('success')" class="mb-4" />
@endif

@if (($filters['supplier_party_id'] ?? null) && ($supplierName ?? null))
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
        نمایش فاکتورهای <span class="font-medium text-gray-800 dark:text-white/90">{{ $supplierName }}</span> —
        <a href="{{ route('suppliers.show', $filters['supplier_party_id']) }}" class="text-brand-500 hover:underline">بازگشت به تامین‌کننده</a>
    </p>
@endif

<x-tables.pro-table
    :columns="$columns"
    :paginator="$invoices"
    :query="$query"
    :filterLabels="$filterLabels"
    empty-message="هنوز خریدی ثبت نشده است"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی تامین‌کننده / شماره فاکتور"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="purchases.visibleColumns"
>
    <x-slot:filters>
        <select name="status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه وضعیت‌ها</option>
            @foreach ($statusLabels as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </x-slot:filters>

    <x-slot:actions>
        <a href="{{ route('purchases.create') }}" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + خرید جدید
        </a>
    </x-slot:actions>

    @foreach ($invoices as $invoice)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <x-tables.ltr x-show="visible.invoice_date" class="px-5 sm:px-6" :value="\Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d')" />
            <td x-show="visible.supplier" class="px-5 py-3 sm:px-6">
                <a href="{{ route('suppliers.show', $invoice->supplier_party_id) }}" class="text-brand-500 hover:underline">{{ $invoice->supplier->name }}</a>
            </td>
            <x-tables.ltr x-show="visible.invoice_no" class="px-5 sm:px-6" :value="$invoice->invoice_no" tone="muted" />
            <td x-show="visible.qty" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ number_format($invoice->total_qty) }}</td>
            <x-tables.num x-show="visible.total" class="px-5 sm:px-6" :value="$invoice->total" type="toman" tone="muted" />
            <td x-show="visible.status" class="px-5 py-3 sm:px-6">
                <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                    {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                </x-ui.badge>
            </td>
            <td x-show="visible.attachments" class="px-5 py-3 sm:px-6">
                @if ($invoice->attachments->isNotEmpty())
                    <a href="{{ route('purchases.show', $invoice) }}" class="text-brand-500 hover:underline">📎 {{ $invoice->attachments->count() }}</a>
                @else
                    —
                @endif
            </td>
            <td x-show="visible.actions" class="px-5 py-3 sm:px-6">
                <div class="flex items-center gap-2">
                    <a href="{{ route('purchases.show', $invoice) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">مشاهده</a>
                    <a href="{{ route('purchases.edit', $invoice) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ویرایش</a>
                </div>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
