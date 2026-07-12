@extends('layouts.app')

@php
    // Sort links are derived from the TableQuery by <x-tables.pro-table>: a column
    // just declares which sort key it maps to. The page no longer builds sort URLs
    // by hand, so a sort click can no longer drop an active filter.
    $columns = [
        ['key' => 'order', 'label' => 'سفارش', 'sort' => 'hub_order_id'],
        ['key' => 'customer', 'label' => 'مشتری'],
        ['key' => 'channel', 'label' => 'کانال'],
        ['key' => 'status', 'label' => 'وضعیت سفارش'],
        ['key' => 'payment_status', 'label' => 'وضعیت پرداخت'],
        ['key' => 'total', 'label' => 'مبلغ (تومان)', 'sort' => 'total'],
        ['key' => 'shipping', 'label' => 'هزینه ارسال', 'sort' => 'shipping_charged'],
        ['key' => 'profit', 'label' => 'سود', 'sort' => 'operational_profit'],
        ['key' => 'profit_status', 'label' => 'وضعیت سود'],
        ['key' => 'order_date', 'label' => 'تاریخ ثبت', 'sort' => 'order_date'],
        ['key' => 'updated_at', 'label' => 'آخرین به‌روزرسانی از هاب', 'sort' => 'updated_at'],
    ];

    $filterLabels = [
        'status' => 'وضعیت سفارش',
        'payment_status' => 'وضعیت پرداخت',
        'profit_status' => 'وضعیت سود',
        'channel_id' => 'کانال',
        'province' => 'استان',
        'date_from' => 'از تاریخ',
        'date_to' => 'تا تاریخ',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="سفارش‌ها" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$orders"
    :query="$query"
    :filterLabels="$filterLabels"
    empty-message="هنوز سفارشی ثبت نشده است"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی شماره سفارش، نام مشتری یا شهر"
    with-date-range
    date-from-value="{{ $filters['date_from'] ?? null }}"
    date-to-value="{{ $filters['date_to'] ?? null }}"
    :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
    storage-key="orders.visibleColumns"
>
    <x-slot:filters>
        <select name="channel_id" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه کانال‌ها</option>
            @foreach ($channels as $channel)
                <option value="{{ $channel->id }}" @selected(($filters['channel_id'] ?? null) == $channel->id)>{{ $channel->name }}</option>
            @endforeach
            @if ($unmappedCount > 0)
                <option value="unmapped" @selected(($filters['channel_id'] ?? null) === 'unmapped')>نامشخص (بدون کانال)</option>
            @endif
        </select>

        <select name="status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه وضعیت‌های سفارش</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->status }}" @selected(($filters['status'] ?? null) === $s->status)>
                    {{ \App\Domain\Orders\Support\OrderStatusPresenter::orderStatus($s->status)['label'] }} ({{ $s->count }})
                </option>
            @endforeach
        </select>

        <select name="payment_status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">وضعیت پرداخت</option>
            <option value="paid" @selected(($filters['payment_status'] ?? null) === 'paid')>پرداخت‌شده</option>
            <option value="unpaid" @selected(($filters['payment_status'] ?? null) === 'unpaid')>پرداخت‌نشده</option>
        </select>

        <select name="province" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه استان‌ها</option>
            @foreach ($provinces as $province)
                <option value="{{ $province }}" @selected(($filters['province'] ?? null) === $province)>{{ $province }}</option>
            @endforeach
        </select>

        <select name="profit_status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <option value="">همه وضعیت‌های سود</option>
            <option value="ok" @selected(($filters['profit_status'] ?? null) === 'ok')>سود ثبت‌شده</option>
            <option value="blocked_missing_cost" @selected(($filters['profit_status'] ?? null) === 'blocked_missing_cost')>مسدود — بدون بها</option>
            <option value="unknown_source" @selected(($filters['profit_status'] ?? null) === 'unknown_source')>منبع ناشناخته</option>
            <option value="needs_review" @selected(($filters['profit_status'] ?? null) === 'needs_review')>نیازمند بازبینی</option>
            <option value="pending" @selected(($filters['profit_status'] ?? null) === 'pending')>در انتظار</option>
        </select>
    </x-slot:filters>

    @foreach ($orders as $order)
        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
            <td x-show="visible.order" class="px-5 py-3 sm:px-6">
                <a href="{{ route('orders.show', $order) }}" class="font-medium text-brand-500 hover:underline">#{{ $order->hub_order_id }}</a>
            </td>
            <td x-show="visible.customer" class="max-w-40 truncate px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $order->customerParty?->name ?? '—' }}</td>
            <td x-show="visible.channel" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $order->channel?->name ?? 'نامشخص' }}</td>
            <td x-show="visible.status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="order" :value="$order->status" /></td>
            <td x-show="visible.payment_status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="payment" :value="$order->payment_status" /></td>
            <x-tables.num x-show="visible.total" :value="$order->total" tone="muted" />
            <x-tables.num x-show="visible.shipping" :value="$order->shipping_charged" tone="muted" />
            <x-tables.num x-show="visible.profit" :value="$order->profit?->operational_profit" :signed="true" />
            <td x-show="visible.profit_status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="profit" :value="$order->profit_status" /></td>
            <td x-show="visible.order_date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($order->order_date) }}</td>
            <td x-show="visible.updated_at" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($order->updated_at) }}</td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
