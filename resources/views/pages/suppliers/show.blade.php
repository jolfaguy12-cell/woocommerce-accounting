@extends('layouts.app')

@php
    $statusLabels = ['draft' => 'ثبت‌شده', 'partial' => 'دریافت جزئی', 'received' => 'دریافت‌شده', 'cancelled' => 'لغوشده'];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$supplier->name" parentLabel="تامین‌کننده‌ها" :parentUrl="route('suppliers.index')" />

<div class="space-y-4">
    <x-common.component-card :title="$supplier->name">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">نام فروشگاه</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $supplier->shop_name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره تلفن</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $supplier->phone ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره حساب</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $supplier->bank_account_number ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">تعداد خریدهای ثبت‌شده</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ number_format($purchases->count()) }}</p>
            </div>
        </div>
    </x-common.component-card>

    <x-common.component-card title="سابقه خرید از این تامین‌کننده">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">تاریخ فاکتور</th>
                        <th class="text-right font-normal">قلم / کالا</th>
                        <th class="text-right font-normal">تعداد</th>
                        <th class="text-right font-normal">قیمت واحد</th>
                        <th class="text-right font-normal">بهای تمام‌شده واحد</th>
                        <th class="text-right font-normal">وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchases as $line)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <x-tables.ltr class="py-2" :value="\Morilog\Jalali\Jalalian::fromCarbon($line->invoice->invoice_date)->format('Y/m/d')" />
                            <td class="text-gray-800 dark:text-white/90">{{ $line->costItem->name }}</td>
                            <x-tables.num :value="$line->qty" tone="muted" />
                            <x-tables.num :value="$line->unit_price" type="toman" tone="muted" />
                            <x-tables.num :value="$line->landed_unit_cost" type="toman" tone="muted" />
                            <td>
                                <x-ui.badge :color="$line->invoice->status === 'received' ? 'success' : ($line->invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                                    {{ $statusLabels[$line->invoice->status] ?? $line->invoice->status }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-400">هنوز خریدی از این تامین‌کننده ثبت نشده است.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-common.component-card>
</div>
@endsection
