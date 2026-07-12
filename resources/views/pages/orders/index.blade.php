@extends('layouts.app')

@php
    $sortUrl = fn (string $key) => route('orders.index', array_merge(
        $filters,
        ['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc']
    ));
    $sortDirFor = fn (string $key) => $sort === $key ? $dir : null;

    $columns = [
        ['key' => 'order', 'label' => 'سفارش', 'sort_url' => $sortUrl('hub_order_id'), 'sort_dir' => $sortDirFor('hub_order_id')],
        ['key' => 'customer', 'label' => 'مشتری'],
        ['key' => 'channel', 'label' => 'کانال'],
        ['key' => 'status', 'label' => 'وضعیت سفارش'],
        ['key' => 'payment_status', 'label' => 'وضعیت پرداخت'],
        ['key' => 'total', 'label' => 'مبلغ (تومان)', 'sort_url' => $sortUrl('total'), 'sort_dir' => $sortDirFor('total')],
        ['key' => 'shipping', 'label' => 'هزینه ارسال', 'sort_url' => $sortUrl('shipping_charged'), 'sort_dir' => $sortDirFor('shipping_charged')],
        ['key' => 'profit', 'label' => 'سود', 'sort_url' => $sortUrl('operational_profit'), 'sort_dir' => $sortDirFor('operational_profit')],
        ['key' => 'profit_status', 'label' => 'وضعیت سود'],
        ['key' => 'order_date', 'label' => 'تاریخ ثبت', 'sort_url' => $sortUrl('order_date'), 'sort_dir' => $sortDirFor('order_date')],
        ['key' => 'updated_at', 'label' => 'آخرین به‌روزرسانی از هاب', 'sort_url' => $sortUrl('updated_at'), 'sort_dir' => $sortDirFor('updated_at')],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="سفارش‌ها" />

<x-tables.pro-table
    :columns="$columns"
    :paginator="$orders"
    empty-message="سفارشی با این فیلترها یافت نشد"
    search-value="{{ $filters['search'] ?? '' }}"
    search-placeholder="جستجوی شماره سفارش، نام مشتری یا شهر"
    with-date-range
    date-from-value="{{ $filters['date_from'] ?? null }}"
    date-to-value="{{ $filters['date_to'] ?? null }}"
    :clear-filters-route="array_filter($filters) ? route('orders.index') : null"
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
            <x-tables.num x-show="visible.total" :value="$order->total" class="text-gray-600 dark:text-gray-300" />
            <x-tables.num x-show="visible.shipping" :value="$order->shipping_charged" class="text-gray-600 dark:text-gray-300" />
            <x-tables.num x-show="visible.profit" :value="$order->profit?->operational_profit" :signed="true" />
            <td x-show="visible.profit_status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="profit" :value="$order->profit_status" /></td>
            <td x-show="visible.order_date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($order->order_date) }}</td>
            <td x-show="visible.updated_at" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($order->updated_at) }}</td>
        </tr>
    @endforeach
</x-tables.pro-table>
@endsection
