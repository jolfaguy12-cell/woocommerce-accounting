@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت خرید" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-common.filter-bar>
            <input
                type="text"
                name="q"
                value="{{ $filters['q'] ?? '' }}"
                placeholder="جستجو تامین‌کننده / شماره فاکتور"
                class="h-9 w-64 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
            >
            <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        </x-common.filter-bar>

        <a href="{{ route('purchases.create') }}" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + خرید جدید
        </a>
    </div>

    <x-tables.data-table
        :headers="['تاریخ', 'تامین‌کننده', 'شماره فاکتور', 'تعداد', 'جمع کل', 'وضعیت', 'پیوست']"
        :paginator="$invoices"
        emptyMessage="هنوز خریدی ثبت نشده است"
    >
        @foreach ($invoices as $invoice)
            @php $total = $invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost; @endphp
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <x-tables.ltr class="p-3 sm:px-6 text-gray-800 dark:text-white/90" :value="\Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d')" />
                <td class="px-5 sm:px-6">
                    <a href="{{ route('purchases.show', $invoice) }}" class="text-brand-500 hover:underline">{{ $invoice->supplier->name }}</a>
                </td>
                <x-tables.ltr class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" :value="$invoice->invoice_no" />
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300">{{ number_format($invoice->lines->sum('qty')) }}</td>
                <x-tables.num class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" :value="$total" type="toman" />
                <td class="px-5 sm:px-6">
                    <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                        {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                    </x-ui.badge>
                </td>
                <td class="px-5 sm:px-6">
                    @if ($invoice->attachments->isNotEmpty())
                        <a href="{{ route('attachments.download', $invoice->attachments->first()) }}" class="text-brand-500 hover:underline">📎</a>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>
@endsection
