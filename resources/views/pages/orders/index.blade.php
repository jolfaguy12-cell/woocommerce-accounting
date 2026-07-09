@extends('layouts.app')

@php
    $columns = [
        ['key' => 'order', 'label' => 'سفارش'],
        ['key' => 'customer', 'label' => 'مشتری'],
        ['key' => 'channel', 'label' => 'کانال'],
        ['key' => 'status', 'label' => 'وضعیت سفارش'],
        ['key' => 'payment_status', 'label' => 'وضعیت پرداخت'],
        ['key' => 'total', 'label' => 'مبلغ (تومان)'],
        ['key' => 'shipping', 'label' => 'هزینه ارسال'],
        ['key' => 'profit', 'label' => 'سود'],
        ['key' => 'profit_status', 'label' => 'وضعیت سود'],
        ['key' => 'order_date', 'label' => 'تاریخ ثبت'],
        ['key' => 'updated_at', 'label' => 'آخرین به‌روزرسانی از هاب'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="سفارش‌ها" />

<div
    class="space-y-4"
    x-data="{
        visible: {{ json_encode(array_fill_keys(array_column($columns, 'key'), true)) }},
        columnsOpen: false,
        init() {
            const saved = JSON.parse(localStorage.getItem('orders.visibleColumns') || '{}');
            Object.assign(this.visible, saved);
        },
        toggle(key) {
            this.visible[key] = !this.visible[key];
            localStorage.setItem('orders.visibleColumns', JSON.stringify(this.visible));
        },
    }"
    x-init="init()"
>
    <x-common.filter-bar>
        <div class="relative">
            <input
                type="text"
                name="search"
                value="{{ $filters['search'] ?? '' }}"
                placeholder="جستجوی شماره سفارش یا نام مشتری"
                class="h-9 w-56 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
            >
        </div>

        <x-form.jalali-date-range :from-value="$filters['date_from'] ?? null" :to-value="$filters['date_to'] ?? null" />

        <select name="channel_id" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
            <option value="">همه کانال‌ها</option>
            @foreach ($channels as $channel)
                <option value="{{ $channel->id }}" @selected(($filters['channel_id'] ?? null) == $channel->id)>{{ $channel->name }}</option>
            @endforeach
            @if ($unmappedCount > 0)
                <option value="unmapped" @selected(($filters['channel_id'] ?? null) === 'unmapped')>نامشخص (بدون کانال)</option>
            @endif
        </select>

        <select name="status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
            <option value="">همه وضعیت‌های سفارش</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->status }}" @selected(($filters['status'] ?? null) === $s->status)>
                    {{ \App\Domain\Orders\Support\OrderStatusPresenter::orderStatus($s->status)['label'] }} ({{ $s->count }})
                </option>
            @endforeach
        </select>

        <select name="payment_status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
            <option value="">وضعیت پرداخت</option>
            <option value="paid" @selected(($filters['payment_status'] ?? null) === 'paid')>پرداخت‌شده</option>
            <option value="unpaid" @selected(($filters['payment_status'] ?? null) === 'unpaid')>پرداخت‌نشده</option>
        </select>

        <select name="profit_status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
            <option value="">همه وضعیت‌های سود</option>
            <option value="ok" @selected(($filters['profit_status'] ?? null) === 'ok')>سود ثبت‌شده</option>
            <option value="blocked_missing_cost" @selected(($filters['profit_status'] ?? null) === 'blocked_missing_cost')>مسدود — بدون بها</option>
            <option value="unknown_source" @selected(($filters['profit_status'] ?? null) === 'unknown_source')>منبع ناشناخته</option>
            <option value="needs_review" @selected(($filters['profit_status'] ?? null) === 'needs_review')>نیازمند بازبینی</option>
            <option value="pending" @selected(($filters['profit_status'] ?? null) === 'pending')>در انتظار</option>
        </select>

        <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        @if (array_filter($filters))
            <a href="{{ route('orders.index') }}" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-600 leading-9 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">پاک کردن فیلترها</a>
        @endif

        {{-- Column visibility --}}
        <div class="relative" @click.away="columnsOpen = false">
            <button type="button" @click="columnsOpen = !columnsOpen" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ستون‌ها</button>
            <div x-show="columnsOpen" x-cloak x-transition class="absolute left-0 z-50 mt-1 w-56 rounded-lg border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                @foreach ($columns as $col)
                    <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                        <input type="checkbox" :checked="visible['{{ $col['key'] }}']" @change="toggle('{{ $col['key'] }}')">
                        {{ $col['label'] }}
                    </label>
                @endforeach
            </div>
        </div>
    </x-common.filter-bar>

    <x-tables.data-table :headers="$columns" :paginator="$orders" emptyMessage="سفارشی با این فیلترها یافت نشد">
        @foreach ($orders as $order)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.order" class="px-5 py-3 sm:px-6">
                    <a href="{{ route('orders.show', $order) }}" class="font-medium text-brand-500 hover:underline">#{{ $order->hub_order_id }}</a>
                </td>
                <td x-show="visible.customer" class="max-w-40 truncate px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $order->customerParty?->name ?? '—' }}</td>
                <td x-show="visible.channel" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $order->channel?->name ?? 'نامشخص' }}</td>
                <td x-show="visible.status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="order" :value="$order->status" /></td>
                <td x-show="visible.payment_status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="payment" :value="$order->payment_status" /></td>
                <td x-show="visible.total" class="whitespace-nowrap px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ number_format($order->total) }}</td>
                <td x-show="visible.shipping" class="whitespace-nowrap px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ number_format($order->shipping_charged) }}</td>
                <td x-show="visible.profit" class="whitespace-nowrap px-5 py-3 sm:px-6 {{ ($order->profit?->operational_profit ?? 0) < 0 ? 'text-error-500' : 'text-gray-600 dark:text-gray-300' }}" dir="ltr">
                    {{ $order->profit?->operational_profit !== null ? number_format($order->profit->operational_profit) : '—' }}
                </td>
                <td x-show="visible.profit_status" class="px-5 py-3 sm:px-6"><x-orders.status-badge type="profit" :value="$order->profit_status" /></td>
                <td x-show="visible.order_date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($order->order_date) }}</td>
                <td x-show="visible.updated_at" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($order->updated_at) }}</td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>
@endsection
