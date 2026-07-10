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
        $costs = app(\App\Domain\Costing\Services\CostResolver::class);
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
        @if ($order->shipping_charged == 0)
            @php
                $freeShippingThreshold = $order->channel?->config['free_shipping_threshold'] ?? null;
                $itemsTotal = (int) $order->items->sum('line_subtotal');
                $freeShippingReason = $freeShippingThreshold && $itemsTotal > $freeShippingThreshold
                    ? 'خرید بالای '.number_format($freeShippingThreshold).' تومان'
                    : null;
            @endphp
            <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15">{!! $icon('box-package') !!}</div>
                <div class="min-w-0">
                    <span class="text-gray-500 dark:text-gray-400">هزینه ارسال</span>
                    <p class="font-medium text-success-600 dark:text-success-400">ارسال رایگان</p>
                    @if ($freeShippingReason)
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $freeShippingReason }}</p>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-common.component-card title="اقلام سفارش">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 font-normal">کالا</th>
                        <th class="text-center font-normal">تعداد</th>
                        <th class="text-center font-normal">بهای تمام‌شده</th>
                        <th class="text-center font-normal">فی</th>
                        <th class="text-center font-normal">جمع</th>
                        @if (($order->profit->channel_fee ?? 0) > 0 && ($order->profit->gross_sale ?? 0) > 0)
                            <th class="text-center font-normal">کارمزد کانال</th>
                        @endif
                        <th class="text-center font-normal">سود فروش</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->items as $item)
                        @php
                            $cost = $item->productMirror ? $costs->resolveFor($item->productMirror) : null;
                            $lineCost = $cost ? $cost['unit_cost'] * $item->qty : null;
                            $lineProfit = $lineCost !== null ? $item->line_total - $lineCost : null;
                            // Basalam (and its own vendor panel) don't track a true
                            // independent per-item commission — they allocate the
                            // order-level fee proportionally by each item's share of
                            // the order's item total. Same method here, so the sum
                            // across items always reconciles to profit->channel_fee.
                            $itemFee = ($order->profit->channel_fee ?? 0) > 0 && ($order->profit->gross_sale ?? 0) > 0
                                ? (int) round($order->profit->channel_fee * $item->line_subtotal / $order->profit->gross_sale)
                                : null;
                        @endphp
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">
                                @if ($item->product_mirror_id)
                                    <a href="{{ route('products.show', $item->product_mirror_id) }}" class="text-inherit hover:underline">{{ $item->name }}</a>
                                @else
                                    {{ $item->name }}
                                    <x-ui.badge color="error" size="sm">بدون نگاشت</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-center text-gray-600 dark:text-gray-300">{{ number_format($item->qty) }}</td>
                            <td class="text-center text-gray-600 dark:text-gray-300" dir="ltr">
                                @if ($lineCost !== null)
                                    {{ number_format($lineCost) }}
                                @elseif ($item->product_mirror_id)
                                    <button type="button" onclick='openQuickCostModal({{ $item->product_mirror_id }}, @json($item->name))'>
                                        <x-ui.badge color="error" size="sm">ثبت نشده</x-ui.badge>
                                    </button>
                                @else
                                    <x-ui.badge color="error" size="sm">ثبت نشده</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-center text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($item->unit_price) }}</td>
                            <td class="text-center text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($item->line_total) }}</td>
                            @if (($order->profit->channel_fee ?? 0) > 0 && ($order->profit->gross_sale ?? 0) > 0)
                                <td class="text-center text-gray-600 dark:text-gray-300" dir="ltr" title="تخمینی — به نسبت سهم این آیتم از جمع سفارش">
                                    {{ $itemFee !== null ? '−'.number_format($itemFee) : '—' }}
                                </td>
                            @endif
                            <td class="text-center" dir="ltr">
                                @if ($lineProfit !== null)
                                    <span class="{{ $lineProfit < 0 ? 'text-error-500' : 'text-success-600 dark:text-success-400' }}">{{ number_format($lineProfit) }}</span>
                                @else
                                    <span class="text-error-500 text-base leading-none">✕</span>
                                @endif
                            </td>
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

            <form method="POST" action="{{ route('orders.shipping', $order) }}" class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                @csrf
                <span class="text-sm text-gray-700 dark:text-gray-300">هزینه حمل واقعی:</span>
                <input
                    type="number"
                    name="real_cost"
                    dir="ltr"
                    value="{{ $order->shippingCost?->real_cost }}"
                    placeholder="تومان"
                    class="h-9 w-28 min-w-0 flex-1 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90 sm:flex-none sm:w-36"
                >
                <button type="submit" class="h-9 shrink-0 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
                    ثبت و بازمحاسبه
                </button>
            </form>

            <div class="mt-2 flex flex-wrap items-center gap-2 pt-1">
                <form method="POST" action="{{ route('orders.packaging', $order) }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <span class="text-sm text-gray-700 dark:text-gray-300">هزینه بسته‌بندی این سفارش:</span>
                    <input
                        type="number"
                        name="real_cost"
                        dir="ltr"
                        value="{{ $order->packagingCost?->real_cost }}"
                        placeholder="{{ $order->profit?->packaging_cost !== null ? number_format($order->profit->packaging_cost) : 'تومان' }}"
                        class="h-9 w-28 min-w-0 flex-1 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90 sm:flex-none sm:w-36"
                    >
                    <button type="submit" class="h-9 shrink-0 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">
                        ثبت و بازمحاسبه
                    </button>
                </form>
                @if ($order->packagingCost)
                    <form method="POST" action="{{ route('orders.packaging.reset', $order) }}" onsubmit="return confirm('هزینه بسته‌بندی دستی حذف و بر اساس فرمول پله‌ای/پیش‌فرض بازمحاسبه شود؟')">
                        @csrf
                        <button type="submit" title="بازنشانی به حالت خودکار (فرمول پله‌ای/پیش‌فرض)" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M19.4 13a7.97 7.97 0 0 0 0-2l2.1-1.65a.5.5 0 0 0 .12-.64l-2-3.46a.5.5 0 0 0-.6-.22l-2.49 1a7.97 7.97 0 0 0-1.73-1l-.38-2.65A.5.5 0 0 0 14 2h-4a.5.5 0 0 0-.5.42L9.12 5.07a7.97 7.97 0 0 0-1.73 1l-2.49-1a.5.5 0 0 0-.6.22l-2 3.46a.5.5 0 0 0 .12.64L4.6 11a7.97 7.97 0 0 0 0 2l-2.1 1.65a.5.5 0 0 0-.12.64l2 3.46a.5.5 0 0 0 .6.22l2.49-1a7.97 7.97 0 0 0 1.73 1l.38 2.65A.5.5 0 0 0 10 22h4a.5.5 0 0 0 .5-.42l.38-2.65a7.97 7.97 0 0 0 1.73-1l2.49 1a.5.5 0 0 0 .6-.22l2-3.46a.5.5 0 0 0-.12-.64L19.4 13Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"></path><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"></circle></svg>
                        </button>
                    </form>
                @endif
            </div>
        </x-common.component-card>

        <x-common.component-card title="تفکیک سود">
            @if (! $order->profit)
                <p class="text-sm text-gray-500 dark:text-gray-400">سودی محاسبه نشده (سفارش هنوز معتبر نیست یا در صف است).</p>
            @else
                @php
                    $discountLabel = ($order->profit->channel_discount ?? 0) > 0 ? 'تخفیف کوپن (باسلام)' : 'تخفیف';
                    $rows = [
                        ['فروش ناخالص', $order->profit->gross_sale],
                        [$discountLabel, -$order->profit->discounts],
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

                    @php
                        $opProfit = $order->profit->operational_profit;
                        $opColor = match (true) {
                            $opProfit === null => 'text-error-500',
                            $opProfit < 0 => 'text-error-500',
                            default => 'text-success-600 dark:text-success-400',
                        };
                    @endphp
                    <div class="flex justify-between py-2 text-base font-bold">
                        <span class="text-gray-500 dark:text-gray-400">سود عملیاتی</span>
                        <span dir="ltr" class="{{ $opColor }}">
                            {{ $opProfit !== null ? number_format($opProfit) : 'مسدود' }}
                        </span>
                    </div>

                    @if ($order->profit->packaging_cost !== null)
                        <div class="flex justify-between border-t border-gray-100 py-1 text-xs text-gray-400 dark:border-gray-800 dark:text-gray-500">
                            <span>
                                هزینه بسته‌بندی (فقط ثبت — در سود عملیاتی لحاظ نشده)
                                @if ($order->profit->packaging_cost_basis === 'manual') دستی
                                @elseif ($order->profit->packaging_cost_basis === 'tier') پلکانی، وزن {{ number_format($order->profit->package_weight_grams) }} گرم
                                @else پیش‌فرض، وزن {{ number_format($order->profit->package_weight_grams) }} گرم
                                @endif
                            </span>
                            <span dir="ltr">{{ number_format($order->profit->packaging_cost) }}</span>
                        </div>
                    @endif

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

    <x-common.component-card title="یادداشت‌ها">
        <form method="POST" action="{{ route('orders.notes.store', $order) }}" class="space-y-3 border-b border-gray-100 pb-4 dark:border-gray-800">
            @csrf
            <textarea name="body" required rows="3" placeholder="یادداشت جدید…"
                class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"></textarea>
            @error('body')<p class="text-xs text-error-500">{{ $message }}</p>@enderror

            @if ($noteRecipientOptions->isNotEmpty())
                <div>
                    <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-400">ارسال به (اختیاری):</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($noteRecipientOptions as $user)
                            <label class="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="recipients[]" value="{{ $user->id }}" class="rounded border-gray-300">
                                {{ $user->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">ثبت یادداشت</button>
        </form>

        <div class="mt-4 space-y-3">
            @forelse ($order->notes as $note)
                <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $note->author?->name ?? 'کاربر حذف‌شده' }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($note->created_at) }}</span>
                            @if (auth()->id() === $note->created_by || auth()->user()->hasRole('admin'))
                                <form method="POST" action="{{ route('notes.destroy', $note) }}" onsubmit="return confirm('این یادداشت حذف شود؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-error-500 hover:underline">حذف</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $note->body }}</p>
                    @if ($note->recipients->isNotEmpty())
                        <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                            ارسال‌شده به: {{ $note->recipients->pluck('user.name')->filter()->implode('، ') }}
                        </p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-500">هنوز یادداشتی برای این سفارش ثبت نشده.</p>
            @endforelse
        </div>
    </x-common.component-card>
</div>

{{-- Quick cost/wholesale registration, opened from the "ثبت نشده" badge above. --}}
<x-ui.modal x-data="{ open: false }" @open-quick-cost-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" id="quick-cost-form">
        @csrf
        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت سریع بهای تمام‌شده</h4>
        <p id="quick-cost-product-name" class="mb-4 text-sm text-gray-500 dark:text-gray-400"></p>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">بهای تمام‌شده (تومان)</label>
        <input type="text" inputmode="numeric" id="quick-cost-unit-cost-display" dir="ltr" autocomplete="off" required
            oninput="formatTomanInput(this, '#quick-cost-unit-cost-raw')"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <input type="hidden" id="quick-cost-unit-cost-raw" name="unit_cost">

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">قیمت عمده داخلی (تومان) — اختیاری</label>
        <input type="text" inputmode="numeric" id="quick-cost-wholesale-display" dir="ltr" autocomplete="off"
            oninput="formatTomanInput(this, '#quick-cost-wholesale-raw')"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <input type="hidden" id="quick-cost-wholesale-raw" name="wholesale_price">

        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">اگر این کالا تنوع (رنگ/سایز و ...) داشته باشد، مقادیر برای والد و همه تنوع‌های آن هم‌زمان ثبت می‌شود.</p>

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت</button>
        </div>
    </form>
</x-ui.modal>

<script>
    function openQuickCostModal(productId, name) {
        document.getElementById('quick-cost-form').action = '{{ url('products') }}/' + productId + '/quick-cost';
        document.getElementById('quick-cost-product-name').textContent = name;
        document.getElementById('quick-cost-unit-cost-display').value = '';
        document.getElementById('quick-cost-unit-cost-raw').value = '';
        document.getElementById('quick-cost-wholesale-display').value = '';
        document.getElementById('quick-cost-wholesale-raw').value = '';
        window.dispatchEvent(new CustomEvent('open-quick-cost-modal'));
    }
</script>
@endsection
