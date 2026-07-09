@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'سفارش #'.$order->hub_order_id" />

@if (session('success'))
    <div class="mb-4"><x-ui.alert variant="success" :message="session('success')" /></div>
@endif

<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <x-orders.status-badge type="order" :value="$order->status" />
        <x-orders.status-badge type="financial" :value="$order->financial_state" />
        <x-orders.status-badge type="profit" :value="$order->profit_status" />
        <x-orders.status-badge type="payment" :value="$order->payment_status" />

        <form method="POST" action="{{ route('orders.recalc', $order) }}" class="mr-auto">
            @csrf
            <button type="submit" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                بازمحاسبه سود
            </button>
        </form>
    </div>

    @php
        $jp = \App\Domain\Accounting\Support\JalaliPeriod::class;
        $icon = fn ($name) => \App\Helpers\MenuHelper::getIconSvg($name);
    @endphp
    <div class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/15">{!! $icon('user-profile') !!}</div>
            <div class="min-w-0">
                <span class="text-gray-500 dark:text-gray-400">مشتری</span>
                <p class="truncate font-medium text-gray-800 dark:text-white/90">{{ $order->customerParty?->name ?? 'ثبت نشده' }}</p>
                @if ($order->customerParty?->phone)
                    <p class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $order->customerParty->phone }}</p>
                @endif
            </div>
        </div>
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/15">{!! $icon('calendar') !!}</div>
            <div class="min-w-0">
                <span class="text-gray-500 dark:text-gray-400">تاریخ ثبت سفارش</span>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $jp::fmtDateTime($order->order_date) }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $jp::humanDiff($order->order_date) }}</p>
            </div>
        </div>
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/15">{!! $icon('income-plus') !!}</div>
            <div class="min-w-0">
                <span class="text-gray-500 dark:text-gray-400">تاریخ پرداخت</span>
                @if ($order->date_paid)
                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $jp::fmtDateTime($order->date_paid) }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $jp::humanDiff($order->date_paid) }}</p>
                @else
                    <p class="font-medium text-gray-800 dark:text-white/90">پرداخت نشده</p>
                @endif
            </div>
        </div>
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/15">{!! $icon('exchange-arrows') !!}</div>
            <div class="min-w-0">
                <span class="text-gray-500 dark:text-gray-400">آخرین همگام‌سازی</span>
                <p class="font-medium text-gray-800 dark:text-white/90">{{ $jp::fmtDateTime($order->updated_at) }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $jp::humanDiff($order->updated_at) }}</p>
            </div>
        </div>
        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500 dark:bg-brand-500/15">{!! $icon('shopping-cart') !!}</div>
            <div class="min-w-0">
                <span class="text-gray-500 dark:text-gray-400">کانال فروش</span>
                <p class="truncate font-medium text-gray-800 dark:text-white/90">{{ $order->channel?->name ?? $order->raw_source_value ?? '—' }}</p>
                @if ($order->payment_method_title)
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $order->payment_method_title }}</p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-common.component-card title="اقلام سفارش">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-right text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 font-normal">کالا</th>
                        <th class="font-normal">تعداد</th>
                        <th class="font-normal">فی</th>
                        <th class="font-normal">جمع</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">
                                @if ($item->product_mirror_id)
                                    <a href="{{ route('products.show', $item->product_mirror_id) }}" class="text-inherit hover:underline">{{ $item->name }}</a>
                                @else
                                    {{ $item->name }}
                                    <x-ui.badge color="error" size="sm">بدون نگاشت</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-gray-600 dark:text-gray-300">{{ number_format($item->qty) }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($item->unit_price) }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($item->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <div class="flex justify-between">
                    <span>تخفیف</span>
                    <span dir="ltr">{{ number_format($order->discount_total) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>حمل دریافتی از مشتری</span>
                    <span dir="ltr">{{ number_format($order->shipping_charged) }}</span>
                </div>
                <div class="flex justify-between font-medium text-gray-800 dark:text-white/90">
                    <span>جمع کل</span>
                    <span dir="ltr">{{ number_format($order->total) }}</span>
                </div>
            </div>

            <form method="POST" action="{{ route('orders.shipping', $order) }}" class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                @csrf
                <span class="text-sm text-gray-700 dark:text-gray-300">هزینه حمل واقعی:</span>
                <input
                    type="number"
                    name="real_cost"
                    dir="ltr"
                    value="{{ $order->shippingCost?->real_cost }}"
                    placeholder="تومان"
                    class="h-9 w-36 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90"
                >
                <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
                    ثبت و بازمحاسبه
                </button>
            </form>
        </x-common.component-card>

        <x-common.component-card title="تفکیک سود">
            @if (! $order->profit)
                <p class="text-sm text-gray-500 dark:text-gray-400">سودی محاسبه نشده (سفارش هنوز معتبر نیست یا در صف است).</p>
            @else
                @php
                    $rows = [
                        ['فروش ناخالص', $order->profit->gross_sale],
                        ['تخفیف', -$order->profit->discounts],
                        ['فروش خالص', $order->profit->net_sale],
                        ['بهای تمام‌شده', $order->profit->product_cost !== null ? -$order->profit->product_cost : null],
                        ['حمل دریافتی', $order->profit->shipping_charged],
                        ['حمل واقعی ('.($order->profit->shipping_basis ?? '—').')', $order->profit->shipping_real !== null ? -$order->profit->shipping_real : null],
                        ['کارمزد کانال', -$order->profit->channel_fee],
                    ];
                @endphp
                <div class="space-y-1 text-sm">
                    @foreach ($rows as [$label, $value])
                        <div class="flex justify-between border-b border-gray-100 py-1 last:border-0 dark:border-gray-800">
                            <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                            <span dir="ltr" class="{{ $value !== null && $value < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}">
                                {{ $value !== null ? number_format($value) : 'نامشخص' }}
                            </span>
                        </div>
                    @endforeach

                    <div class="flex justify-between py-2 text-base font-bold">
                        <span>سود عملیاتی</span>
                        <span dir="ltr" class="{{ ($order->profit->operational_profit ?? 0) < 0 ? 'text-error-500' : 'text-success-600 dark:text-success-400' }}">
                            {{ $order->profit->operational_profit !== null ? number_format($order->profit->operational_profit) : 'مسدود' }}
                        </span>
                    </div>

                    @if ($order->profit->cost_breakdown)
                        <div class="mt-2 rounded-md bg-gray-50 p-2 text-xs dark:bg-white/5">
                            @foreach ($order->profit->cost_breakdown as $c)
                                <div class="flex justify-between py-0.5">
                                    <span>{{ $c['item'] }} ×{{ number_format($c['qty']) }} <span class="text-gray-500 dark:text-gray-400">({{ $c['source'] }})</span></span>
                                    <span dir="ltr">{{ number_format($c['line_cost']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($order->refunds->isNotEmpty())
                <div class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-800">
                    @foreach ($order->refunds as $refund)
                        <div class="flex justify-between text-sm text-error-500">
                            <span>برگشت: {{ $refund->reason }}</span>
                            <span dir="ltr">-{{ number_format($refund->amount) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-common.component-card>
    </div>
</div>
@endsection
