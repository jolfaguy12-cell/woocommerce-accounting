@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'مشتری: '.$party->name" />

<div class="space-y-4">
    <x-common.component-card :title="$party->name">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                @foreach ($summary['channels'] as $channelName)
                    <x-ui.badge color="light" size="sm">{{ $channelName }}</x-ui.badge>
                @endforeach
                @if ($party->is_wholesale)
                    <x-ui.badge color="primary" size="sm">مشتری عمده</x-ui.badge>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="$dispatch('open-settlement-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ثبت تسویه
                </button>
                <button type="button" @click="$dispatch('open-credit-sale-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    فروش اعتباری
                </button>
                <button type="button" @click="$dispatch('open-write-off-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-dashed border-gray-300 px-3 text-sm text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5">
                    سوخت مطالبات
                </button>
                <form method="POST" action="{{ route('customers.wholesale', $party) }}">
                    @csrf
                    <input type="hidden" name="is_wholesale" value="{{ $party->is_wholesale ? '0' : '1' }}">
                    <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        {{ $party->is_wholesale ? 'حذف برچسب «مشتری عمده»' : 'علامت‌گذاری به‌عنوان «مشتری عمده»' }}
                    </button>
                </form>
            </div>
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

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]" x-data="{ editingPhone: false }">
            <h3 class="mb-3 text-base font-medium text-gray-800 dark:text-white/90">اطلاعات تماس</h3>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">شماره تماس</span>
                    <div x-show="!editingPhone" class="flex items-center gap-2">
                        <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $party->phone ?: '—' }}</span>
                        <button type="button" @click="editingPhone = true" class="text-xs text-brand-500 hover:underline">{{ $party->phone ? 'ویرایش' : 'ثبت شماره' }}</button>
                    </div>
                    <form x-show="editingPhone" x-cloak method="POST" action="{{ route('customers.phone', $party) }}" class="flex items-center gap-2">
                        @csrf
                        <input type="text" name="phone" value="{{ $party->phone }}" dir="ltr" placeholder="09xxxxxxxxx" class="h-8 w-36 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 dark:border-gray-700 dark:text-white/90">
                        <button type="submit" class="text-xs text-brand-500 hover:underline">ذخیره</button>
                        <button type="button" @click="editingPhone = false" class="text-xs text-gray-500 hover:underline dark:text-gray-400">انصراف</button>
                    </form>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">ایمیل</span>
                    <span class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $party->email ?: '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                    <span class="shrink-0 text-gray-500 dark:text-gray-400">آدرس</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $party->address ?: '—' }}</span>
                </div>
            </div>
        </div>

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

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">مانده حساب</h3>
                @if ($balance['net'] > 0)
                    <x-ui.badge color="error" size="sm">بدهکار</x-ui.badge>
                @elseif ($balance['net'] < 0)
                    <x-ui.badge color="success" size="sm">بستانکار</x-ui.badge>
                @else
                    <x-ui.badge color="light" size="sm">تسویه</x-ui.badge>
                @endif
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">بدهی باز</span>
                    <span class="font-medium {{ $balance['open'] > 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">{{ number_format($balance['open']) }} تومان</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">اعتبار نزد فروشگاه</span>
                    <span class="font-medium {{ $balance['credit'] > 0 ? 'text-success-600 dark:text-success-400' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">{{ number_format($balance['credit']) }} تومان</span>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">فقط سفارش‌های ۱۱ تیر ۱۴۰۵ (۲۰۲۶-۰۷-۱۱) به بعد در این محاسبه لحاظ می‌شوند.</p>
        </div>
    </div>

    <x-common.component-card title="تاریخچه تسویه‌ها و بدهی‌های ایجادشده">
        @if ($settlementHistory->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">هنوز پرداخت، بدهی دستی یا سوخت مطالباتی برای این مشتری ثبت نشده است.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">تاریخ</th>
                        <th class="text-right font-normal">نوع</th>
                        <th class="text-center font-normal">مبلغ (تومان)</th>
                        <th class="text-right font-normal">بابت</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($settlementHistory as $entry)
                        @php $model = $entry['model']; @endphp
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/50">
                            <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($model->created_at) }}</td>
                            <td class="text-gray-600 dark:text-gray-300">
                                @if ($entry['kind'] === 'payment')
                                    <x-ui.badge color="success" size="sm">دریافت وجه</x-ui.badge>
                                    @if ($model->bankAccount)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">— {{ $model->bankAccount->name }}</span>
                                    @endif
                                @elseif ($entry['kind'] === 'credit_sale')
                                    <x-ui.badge color="warning" size="sm">بدهی ایجادشده (فروش اعتباری)</x-ui.badge>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">— {{ $model->description }}</span>
                                @else
                                    <x-ui.badge color="error" size="sm">سوخت مطالبات</x-ui.badge>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">— {{ $model->description }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-center text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($entry['kind'] === 'credit_sale' ? $model->total_due : $model->amount) }}</td>
                            <td class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($entry['kind'] === 'credit_sale')
                                    {{ $model->status === 'settled' ? 'تسویه‌شده' : 'باز — مانده '.number_format($model->remaining()) }}
                                @else
                                    @forelse ($model->settlements as $s)
                                        <div>
                                            @if ($s->creditOrder?->order)
                                                <a href="{{ route('orders.show', $s->creditOrder->order) }}" class="text-brand-500 hover:underline">سفارش #{{ $s->creditOrder->order->hub_order_id }}</a>
                                            @else
                                                فروش اعتباری دستی
                                            @endif
                                            <span dir="ltr">({{ number_format($s->amount) }})</span>
                                        </div>
                                    @empty
                                        —
                                    @endforelse
                                    @if ($entry['kind'] === 'payment' && $model->amount > $model->settlements->sum('amount'))
                                        <div>اعتبار نزد فروشگاه <span dir="ltr">({{ number_format($model->amount - $model->settlements->sum('amount')) }})</span></div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-common.component-card>

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

<x-receivables.settlement-modal :party="$party" :bank-accounts="$bankAccounts" />

<x-ui.modal :isOpen="$errors->hasAny(['amount', 'description']) && old('_credit_sale_modal')" @open-credit-sale-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('customers.credit-sale', $party) }}">
        @csrf
        <input type="hidden" name="_credit_sale_modal" value="1">
        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">فروش اعتباری</h4>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">افزایش دستی بدهی {{ $party->name }} — کالا/خدمت اکنون داده شده، پرداختش بعداً.</p>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ (تومان)</label>
        <input type="text" inputmode="numeric" dir="ltr" autocomplete="off" required
            value="{{ old('amount') && old('_credit_sale_modal') ? number_format((int) old('amount')) : '' }}"
            oninput="formatTomanInput(this, '#credit-sale-amount-raw')"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <input type="hidden" id="credit-sale-amount-raw" name="amount" value="{{ old('_credit_sale_modal') ? old('amount') : '' }}">
        @error('amount')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">توضیح</label>
        <input type="text" name="description" value="{{ old('_credit_sale_modal') ? old('description') : '' }}" required class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('description')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت فروش اعتباری</button>
        </div>
    </form>
</x-ui.modal>

<x-ui.modal :isOpen="$errors->hasAny(['amount', 'description']) && old('_write_off_modal')" @open-write-off-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('customers.write-off', $party) }}">
        @csrf
        <input type="hidden" name="_write_off_modal" value="1">
        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">سوخت مطالبات</h4>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">کاهش دستی بدهی {{ $party->name }} — به‌عنوان هزینه مطالبات سوخت‌شده ثبت می‌شود، نه اعتبار. مانده بدهکاری فعلی: <span dir="ltr">{{ number_format($balance['open']) }}</span> تومان.</p>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">مبلغ (تومان)</label>
        <input type="text" inputmode="numeric" dir="ltr" autocomplete="off" required
            value="{{ old('amount') && old('_write_off_modal') ? number_format((int) old('amount')) : '' }}"
            oninput="formatTomanInput(this, '#write-off-amount-raw')"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <input type="hidden" id="write-off-amount-raw" name="amount" value="{{ old('_write_off_modal') ? old('amount') : '' }}">
        @error('amount')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">دلیل</label>
        <input type="text" name="description" value="{{ old('_write_off_modal') ? old('description') : '' }}" required class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('description')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600">ثبت سوخت مطالبات</button>
        </div>
    </form>
</x-ui.modal>
@endsection
