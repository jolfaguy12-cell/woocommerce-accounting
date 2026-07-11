@props(['orders' => []])

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
    <div class="flex flex-col gap-2 mb-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">سفارش‌های اخیر</h3>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('orders.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-theme-sm font-medium text-gray-700 shadow-theme-xs hover:bg-gray-50 hover:text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-gray-200">
                مشاهده بیشتر
            </a>
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
                @forelse ($orders as $order)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-3 whitespace-nowrap">
                            <p class="font-medium text-gray-800 text-theme-sm dark:text-white/90">#{{ $order['hub_order_id'] }}</p>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $order['date'] }} - {{ $order['time'] }}</p>
                        </td>
                        <td class="py-3 text-center whitespace-nowrap">
                            <p class="text-gray-500 text-theme-sm dark:text-gray-400" dir="ltr">{{ number_format($order['total']) }} تومان</p>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <x-ui.badge :color="$order['status_color']" size="sm">{{ $order['status_label'] }}</x-ui.badge>
                        </td>
                        <td class="py-3 whitespace-nowrap">
                            <a href="{{ route('orders.show', $order['id']) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                مشاهده
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td colspan="5" class="py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">هنوز سفارشی ثبت نشده است</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
