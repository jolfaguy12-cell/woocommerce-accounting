@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="محصولات" />

<div class="space-y-4">
    <x-common.filter-bar>
        <input
            type="text"
            name="q"
            value="{{ $filters['q'] ?? '' }}"
            placeholder="جستجو نام / SKU / شناسه"
            class="h-9 w-56 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
        >
        <label class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="mapping" value="unmapped" onchange="this.form.submit()" @checked(($filters['mapping'] ?? null) === 'unmapped')>
            فقط بدون بهای تمام‌شده
        </label>
        <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
    </x-common.filter-bar>

    <x-tables.data-table
        :headers="['محصول', 'نوع', 'SKU', 'قیمت سایت', 'موجودی', 'بهای تمام‌شده']"
        :paginator="$products"
        emptyMessage="محصولی یافت نشد — با acc:sync:product همگام‌سازی کنید"
    >
        @foreach ($products as $p)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="p-3 sm:px-6">
                    <a href="{{ route('products.show', $p) }}" class="text-brand-500 hover:underline">{{ $p->name }}</a>
                    <span class="mr-2 text-xs text-gray-500 dark:text-gray-400" dir="ltr">#{{ $p->hub_product_id }}</span>
                </td>
                <td class="px-5 sm:px-6"><x-ui.badge color="light" size="sm">{{ $p->type }}</x-ui.badge></td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $p->sku ?? '—' }}</td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $p->price !== null ? number_format($p->price) : '—' }}</td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300">{{ $p->stock_quantity !== null ? number_format($p->stock_quantity) : '—' }}</td>
                <td class="px-5 sm:px-6">
                    @php $mapped = $p->costMapping?->status === 'mapped'; @endphp
                    <x-ui.badge :color="$mapped ? 'success' : ($p->type === 'variable' ? 'light' : 'error')" size="sm">
                        {{ $mapped ? 'ثبت‌شده' : ($p->type === 'variable' ? '— (والد)' : 'ثبت‌نشده') }}
                    </x-ui.badge>
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>
@endsection
