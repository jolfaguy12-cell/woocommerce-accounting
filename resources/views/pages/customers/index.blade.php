@extends('layouts.app')

@php
    $columns = [
        ['key' => 'name', 'label' => 'نام و نام‌خانوادگی'],
        ['key' => 'channel', 'label' => 'کانال'],
        ['key' => 'orders', 'label' => 'تعداد خریدها', 'align' => 'center'],
        ['key' => 'total_volume', 'label' => 'حجم کل خرید (تومان)', 'align' => 'center'],
        ['key' => 'last_order', 'label' => 'آخرین خرید'],
        ['key' => 'wholesale', 'label' => 'وضعیت'],
    ];

    $sortUrl = fn (string $key) => route('customers.index', array_merge(
        $filters,
        ['sort' => $key, 'dir' => ($sort === $key && $dir === 'desc') ? 'asc' : 'desc']
    ));
    $sortDirFor = fn (string $key) => $sort === $key ? $dir : null;
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مدیریت مشتریان" />

<div
    class="space-y-4"
    x-data="{
        visible: {{ json_encode(array_fill_keys(array_column($columns, 'key'), true)) }},
        columnsOpen: false,
        init() {
            const saved = JSON.parse(localStorage.getItem('customers.visibleColumns') || '{}');
            Object.assign(this.visible, saved);
        },
        toggle(key) {
            this.visible[key] = !this.visible[key];
            localStorage.setItem('customers.visibleColumns', JSON.stringify(this.visible));
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
                placeholder="جستجوی نام یا شماره تماس"
                class="h-9 w-56 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
            >
        </div>

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

        <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        @if (array_filter($filters))
            <a href="{{ route('customers.index') }}" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-600 leading-9 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">پاک کردن فیلترها</a>
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

    <x-tables.data-table
        :headers="[
            ['key' => 'name', 'label' => 'نام و نام‌خانوادگی', 'sort_url' => $sortUrl('name'), 'sort_dir' => $sortDirFor('name')],
            ['key' => 'channel', 'label' => 'کانال'],
            ['key' => 'orders', 'label' => 'تعداد خریدها', 'align' => 'center', 'sort_url' => $sortUrl('orders_count'), 'sort_dir' => $sortDirFor('orders_count')],
            ['key' => 'total_volume', 'label' => 'حجم کل خرید (تومان)', 'align' => 'center', 'sort_url' => $sortUrl('total_volume'), 'sort_dir' => $sortDirFor('total_volume')],
            ['key' => 'last_order', 'label' => 'آخرین خرید', 'sort_url' => $sortUrl('last_order_at'), 'sort_dir' => $sortDirFor('last_order_at')],
            ['key' => 'wholesale', 'label' => 'وضعیت'],
            'عملیات',
        ]"
        :paginator="$customers"
        emptyMessage="مشتری‌ای با این فیلترها یافت نشد"
    >
        @foreach ($customers as $customer)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.name" class="px-5 py-3 sm:px-6">
                    <a href="{{ route('customers.show', $customer) }}" class="font-medium text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90">{{ $customer->name }}</a>
                    @if ($customer->phone)
                        <p class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $customer->phone }}</p>
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
                <td x-show="visible.orders" class="px-5 py-3 text-center sm:px-6">
                    <div class="flex flex-wrap items-center justify-center gap-1">
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
                <td x-show="visible.total_volume" class="px-5 py-3 text-center text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">
                    {{ number_format((int) $customer->total_volume) }}
                </td>
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
                <td class="px-5 py-3 sm:px-6">
                    <a href="{{ route('customers.show', $customer) }}" class="inline-flex h-8 items-center rounded-md border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">مشاهده</a>
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>
@endsection
