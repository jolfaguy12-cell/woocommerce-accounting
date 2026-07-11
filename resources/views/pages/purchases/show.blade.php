@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'فاکتور خرید #'.$invoice->id" parentLabel="ثبت خرید" :parentUrl="route('purchases.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @if ($errors->has('invoice_date'))
        <x-ui.alert variant="error" :message="$errors->first('invoice_date')" />
    @endif

    <x-common.component-card :title="'فاکتور خرید #'.$invoice->id">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">تامین‌کننده</p>
                    <a href="{{ route('suppliers.show', $invoice->supplier_party_id) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">{{ $invoice->supplier->name }}</a>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شماره فاکتور</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $invoice->invoice_no ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">تاریخ خرید</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ \Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d') }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                    <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                        {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                    </x-ui.badge>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('purchases.edit', $invoice) }}" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ویرایش</a>
                @if ($invoice->status === 'draft' || $invoice->status === 'partial')
                    <form method="POST" action="{{ route('purchases.finalize', $invoice) }}">
                        @csrf
                        <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">ثبت نهایی و صدور سند</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($invoice->attachments->isNotEmpty())
            <p class="mt-3 text-sm">
                <a href="{{ route('attachments.download', $invoice->attachments->first()) }}" class="text-brand-500 hover:underline">📎 مشاهده تصویر فاکتور</a>
            </p>
        @endif
    </x-common.component-card>

    <x-common.component-card title="اقلام فاکتور">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">کالا</th>
                        <th class="text-right font-normal">تعداد</th>
                        <th class="text-right font-normal">قیمت خرید (واحد)</th>
                        <th class="text-right font-normal">هزینه ارسال (واحد)</th>
                        <th class="text-right font-normal">بهای تمام‌شده (واحد)</th>
                        <th class="text-right font-normal">جمع ردیف</th>
                        <th class="text-right font-normal">توضیحات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->lines as $line)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">{{ $line->product->name ?? $line->costItem->name }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($line->qty) }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($line->unit_price) }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($line->shipping_allocated) }}</td>
                            <td class="font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ number_format($line->landed_unit_cost) }}</td>
                            <td class="text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($line->qty * $line->unit_price) }}</td>
                            <td class="text-gray-500 dark:text-gray-400">{{ $line->note ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 font-medium text-gray-800 dark:border-gray-700 dark:text-white/90">
                        <td colspan="5" class="py-2 text-left">جمع کالا:</td>
                        <td dir="ltr">{{ number_format($invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price)) }} تومان</td>
                        <td></td>
                    </tr>
                    <tr class="text-gray-600 dark:text-gray-300">
                        <td colspan="5" class="py-1 text-left">هزینه حمل:</td>
                        <td dir="ltr">{{ number_format($invoice->shipping_cost) }} تومان</td>
                        <td></td>
                    </tr>
                    <tr class="text-lg font-bold text-gray-800 dark:text-white/90">
                        <td colspan="5" class="py-1 text-left">مبلغ کل فاکتور:</td>
                        <td dir="ltr">{{ number_format($invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost) }} تومان</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-400">
            «بهای تمام‌شده» همان قیمتی است که در محاسبه سود سفارش‌ها استفاده می‌شود (قیمت خرید + سهم هزینه ارسال). سفارش‌هایی که سودشان قبلاً نهایی شده با اصلاح این فاکتور دوباره محاسبه نمی‌شوند؛ فقط سفارش‌های در انتظار بهای تمام‌شده به‌روز می‌شوند.
        </p>
    </x-common.component-card>
</div>
@endsection
