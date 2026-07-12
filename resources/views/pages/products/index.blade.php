@extends('layouts.app')

@php
    $columns = [
        ['key' => 'name', 'label' => 'محصول', 'sort' => 'name'],
        ['key' => 'type', 'label' => 'نوع'],
        ['key' => 'sku', 'label' => 'SKU'],
        ['key' => 'price', 'label' => 'قیمت سایت', 'sort' => 'price'],
        ['key' => 'stock', 'label' => 'موجودی', 'sort' => 'stock_quantity'],
        ['key' => 'cost', 'label' => 'بهای تمام‌شده'],
    ];

    $filterLabels = ['mapping' => 'فیلتر', 'sku' => 'SKU', 'name' => 'نام'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="محصولات" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$products"
    :query="$query"
    :filterLabels="$filterLabels"
    empty-message="محصولی یافت نشد — با acc:sync:product همگام‌سازی کنید"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجو نام / SKU / شناسه"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="products.visibleColumns"
>
    <x-slot:filters>
        <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="mapping" value="unmapped" onchange="this.form.submit()" @checked(($filters['mapping'] ?? null) === 'unmapped')>
            فقط بدون بهای تمام‌شده
        </label>
    </x-slot:filters>

    @foreach ($products as $p)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <td x-show="visible.name" class="p-3 sm:px-6">
                <a href="{{ route('products.show', $p) }}" class="text-brand-500 hover:underline">{{ $p->name }}</a>
                <span class="mr-2 text-xs text-gray-500 dark:text-gray-400" dir="ltr">#{{ $p->hub_product_id }}</span>
            </td>
            <td x-show="visible.type" class="px-5 sm:px-6"><x-ui.badge color="light" size="sm">{{ $p->type }}</x-ui.badge></td>
 <x-tables.ltr x-show="visible.sku" class="px-5 sm:px-6" :value="$p->sku" tone="muted" />
 <x-tables.num x-show="visible.price" class="px-5 sm:px-6" :value="$p->price" tone="muted" />
            <td x-show="visible.stock" class="px-5 text-gray-600 sm:px-6 dark:text-gray-300">{{ $p->stock_quantity !== null ? number_format($p->stock_quantity) : '—' }}</td>
            <td x-show="visible.cost" class="px-5 sm:px-6">
                @php $mapped = $p->costMapping?->status === 'mapped'; @endphp
                <x-ui.badge :color="$mapped ? 'success' : ($p->type === 'variable' ? 'light' : 'error')" size="sm">
                    {{ $mapped ? 'ثبت‌شده' : ($p->type === 'variable' ? '— (والد)' : 'ثبت‌نشده') }}
                </x-ui.badge>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
