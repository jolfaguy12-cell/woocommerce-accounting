@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'مشتری: '.$party->name" />

<div class="space-y-4">
    <x-common.component-card :title="$party->name">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($party->phone)
                    <span dir="ltr">{{ $party->phone }}</span>
                @endif
                @foreach ($summary['channels'] as $channelName)
                    <x-ui.badge color="light" size="sm">{{ $channelName }}</x-ui.badge>
                @endforeach
                @if ($party->is_wholesale)
                    <x-ui.badge color="primary" size="sm">مشتری عمده</x-ui.badge>
                @endif
            </div>

            <form method="POST" action="{{ route('customers.wholesale', $party) }}">
                @csrf
                <input type="hidden" name="is_wholesale" value="{{ $party->is_wholesale ? '0' : '1' }}">
                <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    {{ $party->is_wholesale ? 'حذف برچسب «مشتری عمده»' : 'علامت‌گذاری به‌عنوان «مشتری عمده»' }}
                </button>
            </form>
        </div>

        <div class="mt-4 space-y-1.5">
            @if (session('success'))
                <x-ui.alert variant="success" :message="session('success')" />
            @endif
            @if ($summary['unresolved_profit_count'] > 0)
                <x-ui.alert variant="warning" :message="$summary['unresolved_profit_count'].' سفارش معتبر این مشتری هنوز سودشان محاسبه نشده و در جمع سود زیر لحاظ نشده است.'" />
            @endif
        </div>
    </x-common.component-card>

    <div class="grid gap-4 md:grid-cols-2">
        <x-common.component-card title="خلاصه خرید">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">تعداد کل خریدها</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ number_format($summary['orders_count']) }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">پرداخت‌شده / در انتظار / لغوشده</span>
                    <span class="flex items-center gap-1">
                        <x-ui.badge color="success" size="sm">{{ $summary['paid_count'] }}</x-ui.badge>
                        <x-ui.badge color="warning" size="sm">{{ $summary['pending_count'] }}</x-ui.badge>
                        <x-ui.badge color="error" size="sm">{{ $summary['void_count'] }}</x-ui.badge>
                    </span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">حجم کل خرید (سفارش‌های معتبر)</span>
                    <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($summary['total_volume']) }} تومان</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">آخرین خرید</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">
                        {{ $summary['last_order_at'] ? \App\Domain\Accounting\Support\JalaliPeriod::humanDiff(\Illuminate\Support\Carbon::parse($summary['last_order_at'])) : '—' }}
                    </span>
                </div>
            </div>
        </x-common.component-card>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">سودآوری</h3>
                <x-ui.badge :color="$summary['unresolved_profit_count'] > 0 ? 'warning' : 'light'" size="sm">
                    {{ $summary['unresolved_profit_count'] > 0 ? 'ناقص' : 'کامل' }}
                </x-ui.badge>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">سود ایجادشده برای مجموعه</span>
                    <span class="font-medium {{ $summary['profit'] < 0 ? 'text-error-500' : 'text-success-600 dark:text-success-400' }}" dir="ltr">
                        {{ number_format($summary['profit']) }} تومان
                    </span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">سفارش‌های بدون سود محاسبه‌شده</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ number_format($summary['unresolved_profit_count']) }}</span>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">سود عملیاتی نهایی هر سفارش (پس از کسر بها، ارسال، بسته‌بندی و کارمزد کانال/درگاه) — فقط برای سفارش‌های معتبری که سودشان محاسبه شده.</p>
        </div>
    </div>

    <x-common.component-card title="سفارش‌های این مشتری">
        <x-tables.data-table
            :headers="['سفارش', 'کانال', 'وضعیت سفارش', 'وضعیت پرداخت', 'مبلغ (تومان)', 'سود', 'تاریخ ثبت']"
            :paginator="$orders"
            emptyMessage="سفارشی برای این مشتری یافت نشد"
        >
            @foreach ($orders as $order)
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ route('orders.show', $order) }}" class="font-medium text-brand-500 hover:underline">#{{ $order->hub_order_id }}</a>
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $order->channel?->name ?? 'نامشخص' }}</td>
                    <td class="px-5 py-3 sm:px-6"><x-orders.status-badge type="financial" :value="$order->financial_state" /></td>
                    <td class="px-5 py-3 sm:px-6"><x-orders.status-badge type="payment" :value="$order->payment_status" /></td>
                    <td class="whitespace-nowrap px-5 py-3 text-center text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ number_format($order->total) }}</td>
                    <td class="whitespace-nowrap px-5 py-3 text-center sm:px-6 {{ ($order->profit?->operational_profit ?? 0) < 0 ? 'text-error-500' : 'text-gray-600 dark:text-gray-300' }}" dir="ltr">
                        {{ $order->profit?->operational_profit !== null ? number_format($order->profit->operational_profit) : '—' }}
                    </td>
                    <td class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($order->order_date) }}</td>
                </tr>
            @endforeach
        </x-tables.data-table>
    </x-common.component-card>
</div>
@endsection
