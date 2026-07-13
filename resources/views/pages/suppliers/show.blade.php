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

            <div class="flex flex-wrap shrink-0 items-center gap-2">
                <a href="{{ route('purchases.create', ['supplier_party_id' => $supplier->id]) }}"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">
                    + خرید جدید از این تامین‌کننده
                </a>
                <a href="{{ route('purchases.index', ['supplier_party_id' => $supplier->id]) }}"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    مشاهده فاکتورهای خرید
                </a>
                <button type="button"
                    @click="$dispatch('open-supplier-modal', @js($supplier->only(['id', 'name', 'shop_name', 'phone', 'email', 'address', 'bank_account_number', 'notes'])))"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ویرایش
                </button>
                <button type="button" @click="$dispatch('open-pay-supplier-modal')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    پرداخت به تامین‌کننده
                </button>
                <button type="button" @click="$dispatch('open-refund-supplier-modal')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    دریافت بازپرداخت
                </button>
                <button type="button" @click="$dispatch('open-credit-supplier-modal')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ثبت اعتبار دستی
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

    @php $methodLabels = ['bank_transfer' => 'انتقال بانکی', 'cash' => 'نقدی', 'card' => 'کارت به کارت', 'other' => 'سایر']; @endphp
    <x-common.component-card title="آخرین تراکنش‌های مالی">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">تاریخ</th>
                        <th class="text-right font-normal">نوع</th>
                        <th class="text-right font-normal">شرح</th>
                        <th class="text-right font-normal">حساب بانکی/نقدی</th>
                        <th class="text-right font-normal">روش</th>
                        <th class="text-right font-normal">مرجع</th>
                        <th class="text-right font-normal">یادداشت</th>
                        <th class="text-right font-normal">مبلغ</th>
                        <th class="text-right font-normal">ثبت‌کننده</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentTransactions as $line)
                        @php $row = $line->described; $payment = $row['payment']; @endphp
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="whitespace-nowrap py-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['date'] }}</td>
                            <td>
                                @if ($row['type']['url'])
                                    <a href="{{ $row['type']['url'] }}" class="hover:underline"><x-ui.badge :color="$row['type']['color']" size="sm">{{ $row['type']['label'] }}</x-ui.badge></a>
                                @else
                                    <x-ui.badge :color="$row['type']['color']" size="sm">{{ $row['type']['label'] }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-gray-800 dark:text-white/90">{{ $row['description'] }}</td>
                            <td>
                                @if ($payment && $payment->bankAccount)
                                    <a href="{{ route('bank-accounts.show', $payment->bankAccount) }}" class="text-brand-500 hover:underline">{{ $payment->bankAccount->name }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="text-gray-600 dark:text-gray-300">{{ $payment ? ($methodLabels[$payment->method] ?? '—') : '—' }}</td>
                            <x-tables.ltr :value="$payment->reference ?? null" tone="muted" />
                            <td>
                                @if ($payment)
                                    @include('pages.suppliers.partials.note-edit-control', ['payment' => $payment])
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <x-tables.num :value="$line->credit > 0 ? $line->credit : $line->debit" :signed="$line->debit > 0" type="toman" tone="muted" />
                            <td class="text-xs text-gray-500 dark:text-gray-400">{{ $payment->creator->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-6 text-center text-gray-400">هنوز تراکنشی برای این تامین‌کننده ثبت نشده است.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-left">
            <a href="{{ route('suppliers.transactions', $supplier) }}" class="text-sm text-brand-500 hover:underline">مشاهده همه تراکنش‌ها ←</a>
        </p>
    </x-common.component-card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-widgets.ranked-list
            title="پرخریدترین اقلام از این تامین‌کننده"
            :items="$topItems"
            type="toman"
            :moreUrl="route('suppliers.purchase-history', $supplier)"
        />

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
</div>

@include('pages.suppliers.partials.edit-modal')
@include('pages.suppliers.partials.pay-modal')
@include('pages.suppliers.partials.refund-modal')
@include('pages.suppliers.partials.credit-modal')
@endsection
