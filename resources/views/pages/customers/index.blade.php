@extends('layouts.app')

@php
    // This page used to re-implement the pro-table shell by hand (filter bar,
    // column-visibility dropdown, sort URLs). It now composes the real component,
    // so it inherits chips / page size / density / no-results for free.
    $columns = [
        ['key' => 'name', 'label' => 'نام و نام‌خانوادگی', 'sort' => 'name'],
        ['key' => 'channel', 'label' => 'کانال'],
        ['key' => 'orders', 'label' => 'تعداد خریدها', 'sort' => 'orders_count'],
        ['key' => 'total_volume', 'label' => 'حجم کل خرید (تومان)', 'sort' => 'total_volume'],
        ['key' => 'last_order', 'label' => 'آخرین خرید', 'sort' => 'last_order_at'],
        ['key' => 'wholesale', 'label' => 'وضعیت'],
        ['key' => 'actions', 'label' => 'عملیات'],
    ];

    $filterLabels = ['channel_id' => 'کانال', 'wholesale' => 'نوع مشتری'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت مشتریان" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$customers"
    :query="$query"
    :filterLabels="$filterLabels"
    empty-message="هنوز مشتری‌ای ثبت نشده است"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی نام یا شماره تماس"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="customers.visibleColumns"
>
    <x-slot:filters>
        <select name="channel_id" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه کانال‌ها</option>
            @foreach ($channels as $channel)
                <option value="{{ $channel->id }}" @selected(($filters['channel_id'] ?? null) == $channel->id)>{{ $channel->name }}</option>
            @endforeach
        </select>

        <select name="wholesale" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه مشتریان</option>
            <option value="1" @selected(($filters['wholesale'] ?? null) === '1')>فقط مشتریان عمده</option>
            <option value="0" @selected(($filters['wholesale'] ?? null) === '0')>فقط مشتریان غیرعمده</option>
        </select>
    </x-slot:filters>

    @foreach ($customers as $customer)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <td x-show="visible.name" class="px-5 py-3 sm:px-6">
                <a href="{{ route('customers.show', $customer) }}" class="font-medium text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90">{{ $customer->name }}</a>
                @if ($customer->phone)
                    <x-tables.ltr :value="$customer->phone" :cell="false" tone="muted" class="block text-xs" />
                @else
                    <a href="{{ route('customers.show', $customer) }}" class="text-xs text-brand-500 hover:underline">بدون شماره — ثبت شماره</a>
                @endif
            </td>
            <td x-show="visible.channel" class="px-5 py-3 sm:px-6">
                <div class="flex flex-wrap items-center gap-1">
                    @forelse ($channelsByCustomer->get($customer->id, collect()) as $channelName)
                        <x-ui.badge color="light" size="sm">{{ $channelName }}</x-ui.badge>
                    @empty
                        <span class="text-xs text-gray-400 dark:text-gray-500">نامشخص</span>
                    @endforelse
                </div>
            </td>
            <td x-show="visible.orders" class="px-5 py-3 sm:px-6">
                <div class="flex flex-wrap items-center gap-1">
                    @if ($customer->paid_count > 0)
                        <x-ui.badge color="success" size="sm">{{ $customer->paid_count }} پرداخت‌شده</x-ui.badge>
                    @endif
                    @if ($customer->pending_count > 0)
                        <x-ui.badge color="warning" size="sm">{{ $customer->pending_count }} در انتظار</x-ui.badge>
                    @endif
                    @if ($customer->void_count > 0)
                        <x-ui.badge color="error" size="sm">{{ $customer->void_count }} لغوشده</x-ui.badge>
                    @endif
                </div>
            </td>
            <x-tables.num x-show="visible.total_volume" :value="(int) $customer->total_volume" tone="muted" />
            <td x-show="visible.last_order" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">
                {{ $customer->last_order_at ? \App\Domain\Accounting\Support\JalaliPeriod::humanDiff(\Illuminate\Support\Carbon::parse($customer->last_order_at)) : '—' }}
            </td>
            <td x-show="visible.wholesale" class="px-5 py-3 sm:px-6">
                @if ($customer->is_wholesale)
                    <x-ui.badge color="primary" size="sm">مشتری عمده</x-ui.badge>
                @else
                    <span class="text-gray-400 dark:text-gray-500">—</span>
                @endif
            </td>
            <td x-show="visible.actions" class="px-5 py-3 sm:px-6">
                <a href="{{ route('customers.show', $customer) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">مشاهده</a>
            </td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
