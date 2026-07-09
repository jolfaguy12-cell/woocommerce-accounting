{{-- TODO(backend): این جدول فعلاً داده نمایشی (fake) دارد.
     وقتی داشبورد به بک‌اند وصل شد، باید از آخرین ۱۰ سفارش واقعی پر شود:
     Order::with('channel')->latest('order_date')->limit(10)->get()
     (مشابه متد recentOrders قبلی در DashboardController) و دکمه «مشاهده»
     هر ردیف به route('orders.show', $order->id) لینک شود. --}}
@props(['orders' => []])

@php
    $defaultOrders = [
        ['number' => '۱۰۰۳۰', 'date' => '۱۴۰۵/۰۴/۱۸', 'time' => '۱۴:۵۰', 'price' => '۸۰۰,۰۰۰ تومان', 'status' => 'تکمیل‌شده'],
        ['number' => '۱۰۰۲۹', 'date' => '۱۴۰۵/۰۴/۱۸', 'time' => '۱۳:۲۰', 'price' => '۴۴۰,۰۰۰ تومان', 'status' => 'در حال انجام'],
        ['number' => '۱۰۰۲۸', 'date' => '۱۴۰۵/۰۴/۱۷', 'time' => '۱۸:۱۰', 'price' => '۵۶۰,۰۰۰ تومان', 'status' => 'تکمیل‌شده'],
        ['number' => '۱۰۰۲۷', 'date' => '۱۴۰۵/۰۴/۱۷', 'time' => '۱۱:۴۵', 'price' => '۶۸۰,۰۰۰ تومان', 'status' => 'لغوشده'],
        ['number' => '۱۰۰۲۶', 'date' => '۱۴۰۵/۰۴/۱۶', 'time' => '۱۶:۰۵', 'price' => '۸۰۰,۰۰۰ تومان', 'status' => 'تکمیل‌شده'],
        ['number' => '۱۰۰۲۵', 'date' => '۱۴۰۵/۰۴/۱۶', 'time' => '۰۹:۳۰', 'price' => '۴۴۰,۰۰۰ تومان', 'status' => 'در انتظار پرداخت'],
        ['number' => '۱۰۰۲۴', 'date' => '۱۴۰۵/۰۴/۱۵', 'time' => '۲۰:۱۵', 'price' => '۱,۱۸۰,۰۰۰ تومان', 'status' => 'تکمیل‌شده'],
        ['number' => '۱۰۰۲۳', 'date' => '۱۴۰۵/۰۴/۱۴', 'time' => '۱۲:۰۰', 'price' => '۷۰۰,۰۰۰ تومان', 'status' => 'در حال انجام'],
        ['number' => '۱۰۰۲۲', 'date' => '۱۴۰۵/۰۴/۱۳', 'time' => '۱۷:۴۰', 'price' => '۵۶۰,۰۰۰ تومان', 'status' => 'تکمیل‌شده'],
        ['number' => '۱۰۰۲۱', 'date' => '۱۴۰۵/۰۴/۱۳', 'time' => '۱۰:۱۰', 'price' => '۴۴۰,۰۰۰ تومان', 'status' => 'مستردشده'],
    ];

    // حداکثر ۱۰ سفارش اخیر نمایش داده می‌شود؛ «مشاهده بیشتر» کاربر را به فهرست کامل می‌برد.
    $ordersList = array_slice(!empty($orders) ? $orders : $defaultOrders, 0, 10);

    $getStatusClasses = function ($status) {
        $baseClasses = 'rounded-full px-2 py-0.5 text-theme-xs font-medium';

        return match ($status) {
            'تکمیل‌شده' => $baseClasses . ' bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            'در حال انجام' => $baseClasses . ' bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            'لغوشده' => $baseClasses . ' bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            default => $baseClasses . ' bg-gray-100 text-gray-600 dark:bg-gray-500/15 dark:text-gray-400',
        };
    };
@endphp

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
    <div class="flex flex-col gap-2 mb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">سفارش‌های اخیر</h3>
        </div>

        <div class="flex items-center gap-3">
            {{-- TODO(backend): لینک به route('orders.index') وقتی داشبورد وصل شد --}}
            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 hover:text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-gray-200">
                مشاهده بیشتر
            </button>
        </div>
    </div>

    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="min-w-full">
            <thead>
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <th class="py-3 text-right">
                        <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">شماره سفارش</p>
                    </th>
                    <th class="py-3 text-right">
                        <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">تاریخ و ساعت</p>
                    </th>
                    <th class="py-3 text-right">
                        <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">قیمت</p>
                    </th>
                    <th class="py-3 text-right">
                        <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">وضعیت</p>
                    </th>
                    <th class="py-3 text-right">
                        <span class="sr-only">مشاهده</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ordersList as $order)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-3 whitespace-nowrap">
                            <p class="font-medium text-gray-800 text-theme-sm dark:text-white/90" dir="ltr">#{{ $order['number'] }}</p>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $order['date'] }} - {{ $order['time'] }}</p>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $order['price'] }}</p>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <span class="{{ $getStatusClasses($order['status']) }}">
                                {{ $order['status'] }}
                            </span>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            {{-- TODO(backend): href="{{ route('orders.show', $order['id']) }}" --}}
                            <a href="#" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                مشاهده
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
