@extends('layouts.app')

@php
    $columns = [
        ['key' => 'date', 'label' => 'تاریخ', 'sort' => 'date'],
        ['key' => 'type', 'label' => 'نوع'],
        ['key' => 'description', 'label' => 'شرح'],
        ['key' => 'bank_account', 'label' => 'حساب بانکی/نقدی'],
        ['key' => 'method', 'label' => 'روش'],
        ['key' => 'reference', 'label' => 'مرجع'],
        ['key' => 'notes', 'label' => 'یادداشت'],
        ['key' => 'debit', 'label' => 'بدهکار (کاهش بدهی)', 'sort' => 'debit'],
        ['key' => 'credit', 'label' => 'بستانکار (افزایش بدهی)', 'sort' => 'credit'],
        // Deliberately not sortable: it is a running balance, and it only means
        // anything read in date order.
        ['key' => 'balance_after', 'label' => 'مانده پس از تراکنش'],
        ['key' => 'meta', 'label' => 'ثبت‌کننده'],
    ];

    $methodLabels = ['bank_transfer' => 'انتقال بانکی', 'cash' => 'نقدی', 'card' => 'کارت به کارت', 'other' => 'سایر'];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="'تراکنش‌های مالی — '.$supplier->name" parentLabel="تامین‌کننده‌ها" :parentUrl="route('suppliers.index')" />

<div class="mb-4">
    <x-nav.tabs :tabs="$tabs" param="tab" active="transactions" />
</div>

<div class="space-y-4">
    <x-common.component-card title="مانده حساب پرداختنی">
        <p class="text-sm font-medium {{ $balance < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">
            {{ number_format($balance) }} تومان
        </p>
    </x-common.component-card>

    <x-common.component-card title="تراکنش‌های مالی">
        <x-tables.pro-table
            :columns="$columns"
            :paginator="$transactions"
            :query="$query"
            empty-message="هنوز تراکنشی برای این تامین‌کننده ثبت نشده است"
            search-value="{{ $filters['search'] ?? '' }}"
            search-placeholder="جستجوی شرح تراکنش"
            :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
            storage-key="suppliers.transactions.visibleColumns"
        >
            @foreach ($transactions as $line)
                @php
                    $source = $line->entry->source;
                    $isPayment = $source instanceof \App\Domain\Receivables\Models\PartyPayment;
                    $isReturn = $source instanceof \App\Domain\Costing\Models\PurchaseReturn;
                    $isInvoice = $source instanceof \App\Domain\Costing\Models\PurchaseInvoice;
                    $isCredit = $source instanceof \App\Domain\Receivables\Models\SupplierCreditAdjustment;

                    $type = match (true) {
                        $isInvoice => ['label' => 'فاکتور خرید', 'color' => 'light', 'url' => route('purchases.show', $source)],
                        $isReturn => ['label' => 'برگشت از خرید', 'color' => 'warning', 'url' => route('purchases.show', $source->purchase_invoice_id)],
                        $isCredit => ['label' => 'اعتبار دستی', 'color' => 'info', 'url' => null],
                        $isPayment => ['label' => $source->direction === 'out' ? 'پرداخت' : 'بازپرداخت', 'color' => $source->direction === 'out' ? 'success' : 'primary', 'url' => null],
                        default => ['label' => '—', 'color' => 'light', 'url' => null],
                    };
                @endphp
                <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                    <td x-show="visible.date" class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($line->entry->entry_date) }}</td>
                    <td x-show="visible.type" class="px-5 py-3 sm:px-6">
                        @if ($type['url'])
                            <a href="{{ $type['url'] }}" class="hover:underline"><x-ui.badge :color="$type['color']" size="sm">{{ $type['label'] }}</x-ui.badge></a>
                        @else
                            <x-ui.badge :color="$type['color']" size="sm">{{ $type['label'] }}</x-ui.badge>
                        @endif
                    </td>
                    <td x-show="visible.description" class="px-5 py-3 text-gray-800 sm:px-6 dark:text-white/90">{{ $line->entry->description }}</td>
                    <td x-show="visible.bank_account" class="px-5 py-3 sm:px-6">
                        @if ($isPayment && $source->bankAccount)
                            <a href="{{ route('bank-accounts.show', $source->bankAccount) }}" class="text-brand-500 hover:underline">{{ $source->bankAccount->name }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td x-show="visible.method" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">
                        {{ $isPayment ? ($methodLabels[$source->method] ?? '—') : '—' }}
                    </td>
                    <x-tables.ltr x-show="visible.reference" class="px-5 sm:px-6" :value="$isPayment ? $source->reference : null" tone="muted" />
                    <td x-show="visible.notes" class="px-5 py-3 sm:px-6">
                        @if ($isPayment)
                            @include('pages.suppliers.partials.note-edit-control', ['payment' => $source])
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <x-tables.num x-show="visible.debit" class="px-5 py-3 sm:px-6" :value="$line->debit > 0 ? $line->debit : null" tone="positive" />
                    <x-tables.num x-show="visible.credit" class="px-5 py-3 sm:px-6" :value="$line->credit > 0 ? $line->credit : null" tone="negative" />
                    <x-tables.num x-show="visible.balance_after" class="whitespace-nowrap px-5 py-3 sm:px-6" :value="$line->balance_after" />
                    <td x-show="visible.meta" class="px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">
                        {{ $isPayment ? ($source->creator->name ?? '—') : '—' }}
                    </td>
                </tr>
            @endforeach
        </x-tables.pro-table>
    </x-common.component-card>
</div>
@endsection
