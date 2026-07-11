@extends('layouts.app')

@php
    $sortUrl = fn (string $key) => route('products.index', array_merge(
        $filters,
        ['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc']
    ));
    $sortDirFor = fn (string $key) => $sort === $key ? $dir : null;

    $columns = [
        ['key' => 'name', 'label' => 'محصول', 'sort_url' => $sortUrl('name'), 'sort_dir' => $sortDirFor('name')],
        ['key' => 'type', 'label' => 'نوع'],
        ['key' => 'sku', 'label' => 'SKU'],
        ['key' => 'price', 'label' => 'قیمت سایت', 'sort_url' => $sortUrl('price'), 'sort_dir' => $sortDirFor('price')],
        ['key' => 'stock', 'label' => 'موجودی', 'sort_url' => $sortUrl('stock_quantity'), 'sort_dir' => $sortDirFor('stock_quantity')],
        ['key' => 'cost', 'label' => 'بهای تمام‌شده'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="محصولات" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$products"
    empty-message="محصولی یافت نشد — با acc:sync:product همگام‌سازی کنید"
    search-name="q"
    search-value="{{ $filters['q'] ?? '' }}"
    search-placeholder="جستجو نام / SKU / شناسه"
    :clear-filters-route="array_filter($filters) ? route('products.index') : null"
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
            <td x-show="visible.sku" class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $p->sku ?? '—' }}</td>
            <td x-show="visible.price" class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $p->price !== null ? number_format($p->price) : '—' }}</td>
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
