@extends('layouts.app')

@php
    $statusLabels = ['draft' => 'ثبت‌شده', 'partial' => 'دریافت جزئی', 'received' => 'دریافت‌شده', 'cancelled' => 'لغوشده'];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$supplier->name" parentLabel="تامین‌کننده‌ها" :parentUrl="route('suppliers.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @if ($errors->has('amount'))
        <x-ui.alert variant="error" :message="$errors->first('amount')" />
    @endif

    <x-common.component-card :title="$supplier->name">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">نام فروشگاه</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $supplier->shop_name ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شماره تلفن</p>
                    <x-tables.ltr :value="$supplier->phone" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">ایمیل</p>
                    <x-tables.ltr :value="$supplier->email" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شماره حساب</p>
                    <x-tables.ltr :value="$supplier->bank_account_number" :cell="false" class="mt-1 block text-sm font-medium" />
                </div>
                <div class="sm:col-span-2 lg:col-span-2">
                    <p class="text-xs text-gray-500 dark:text-gray-400">آدرس</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $supplier->address ?? '—' }}</p>
                </div>
                @if ($supplier->notes)
                    <div class="sm:col-span-2 lg:col-span-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">یادداشت</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $supplier->notes }}</p>
                    </div>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <button type="button"
                    @click="$dispatch('open-supplier-modal', @js($supplier->only(['id', 'name', 'shop_name', 'phone', 'email', 'address', 'bank_account_number', 'notes'])))"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ویرایش
                </button>
                <button type="button" @click="$dispatch('open-pay-supplier-modal')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">
                    پرداخت به تامین‌کننده
                </button>
            </div>
        </div>
    </x-common.component-card>

    <x-nav.tabs :tabs="$tabs" param="tab" active="overview" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-kpi.card label="خرید این ماه" :value="$kpis['month_value']['value']" unit="تومان" :change="$kpis['month_value']['change']" />
        <x-kpi.card label="تعداد خریداری‌شده این ماه" :value="$kpis['month_qty']['value']" unit="عدد" :change="$kpis['month_qty']['change']" />
        <x-kpi.card label="خرید کل (تمام دوره‌ها)" :value="$kpis['lifetime_value']['value']" unit="تومان" />
    </div>

    <x-financial.summary
        title="وضعیت حساب پرداختنی"
        desc="مانده مبتنی بر سند حسابداری فاکتورهای خرید و پرداخت‌های ثبت‌شده"
        :rows="[['label' => 'مانده قابل پرداخت به تامین‌کننده', 'value' => $payableBalance, 'type' => 'toman', 'signed' => true]]"
    />

    <div class="grid gap-4 lg:grid-cols-2">
        <x-widgets.timeline
            title="آخرین تراکنش‌های مالی"
            :items="$recentTransactions"
            :moreUrl="route('suppliers.transactions', $supplier)"
        />

        <x-widgets.ranked-list
            title="پرخریدترین اقلام از این تامین‌کننده"
            :items="$topItems"
            type="toman"
            :moreUrl="route('suppliers.purchase-history', $supplier)"
        />
    </div>

    <x-common.component-card title="آخرین فاکتورهای خرید">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">تاریخ</th>
                        <th class="text-right font-normal">شماره فاکتور</th>
                        <th class="text-right font-normal">وضعیت</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentInvoices as $invoice)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <x-tables.ltr class="py-2" :value="\Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d')" />
                            <td class="text-gray-800 dark:text-white/90">
                                <a href="{{ route('purchases.show', $invoice) }}" class="text-brand-500 hover:underline">{{ $invoice->invoice_no ?? '#'.$invoice->id }}</a>
                            </td>
                            <td>
                                <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                                    {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-gray-400">هنوز فاکتور خریدی برای این تامین‌کننده ثبت نشده است.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-left">
            <a href="{{ route('purchases.index', ['supplier_party_id' => $supplier->id]) }}" class="text-sm text-brand-500 hover:underline">مشاهده همه فاکتورها ←</a>
        </p>
    </x-common.component-card>
</div>

@include('pages.suppliers.partials.edit-modal')
@include('pages.suppliers.partials.pay-modal')
@endsection
