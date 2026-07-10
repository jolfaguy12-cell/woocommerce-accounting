@extends('layouts.app')

@php
    $statusLabels = ['draft' => 'ثبت‌شده', 'partial' => 'دریافت جزئی', 'received' => 'دریافت‌شده', 'cancelled' => 'لغوشده'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت خرید" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex justify-end">
        <button @click="$dispatch('open-add-purchase-modal')" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + خرید جدید
        </button>
    </div>

    <x-tables.data-table
        :headers="['تاریخ', 'تامین‌کننده', 'شماره فاکتور', 'تعداد اقلام', 'جمع کل', 'وضعیت']"
        :paginator="$invoices"
        emptyMessage="هنوز خریدی ثبت نشده است"
    >
        @foreach ($invoices as $invoice)
            @php $total = $invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost; @endphp
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="p-3 sm:px-6 text-gray-800 dark:text-white/90" dir="ltr">{{ \Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d') }}</td>
                <td class="px-5 sm:px-6">
                    <a href="{{ route('suppliers.show', $invoice->supplier_party_id) }}" class="text-brand-500 hover:underline">{{ $invoice->supplier->name }}</a>
                </td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $invoice->invoice_no ?? '—' }}</td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300">{{ number_format($invoice->lines->count()) }}</td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ number_format($total) }} تومان</td>
                <td class="px-5 sm:px-6">
                    <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                        {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                    </x-ui.badge>
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>
</div>

{{-- Add purchase modal --}}
<x-ui.modal :isOpen="$errors->any()" @open-add-purchase-modal.window="open = true" class="max-w-2xl p-6">
    <form method="POST" action="{{ route('purchases.store') }}">
        @csrf
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">خرید جدید</h4>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تامین‌کننده</label>
                <select name="supplier_party_id" required onchange="document.getElementById('new-purchase-supplier-name-wrap').classList.toggle('hidden', this.value !== '__new__')"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <option value="">انتخاب کنید…</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(old('supplier_party_id') == $s->id)>{{ $s->name }}{{ $s->shop_name ? " ({$s->shop_name})" : '' }}</option>
                    @endforeach
                    <option value="__new__" @selected(old('new_supplier_name'))>+ تامین‌کننده جدید…</option>
                </select>
                @error('supplier_party_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div id="new-purchase-supplier-name-wrap" class="{{ old('new_supplier_name') ? '' : 'hidden' }}">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام تامین‌کننده جدید</label>
                <input type="text" name="new_supplier_name" value="{{ old('new_supplier_name') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('new_supplier_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره فاکتور (اختیاری)</label>
                <input type="text" name="invoice_no" dir="ltr" value="{{ old('invoice_no') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('invoice_no')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تاریخ خرید (اختیاری — پیش‌فرض امروز)</label>
                <input type="text" inputmode="none" placeholder="امروز" autocomplete="off" data-jdp
                    data-jdp-target-value-input="#purchase-invoice-date-g" data-jdp-target-value-type="gregorian"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <input type="hidden" id="purchase-invoice-date-g" name="invoice_date" value="{{ old('invoice_date') }}">
                @error('invoice_date')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">هزینه حمل کل فاکتور (اختیاری)</label>
                <input type="number" name="shipping_cost" min="0" dir="ltr" value="{{ old('shipping_cost', 0) }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('shipping_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-400">به نسبت تعداد بین اقلام تقسیم و به بهای تمام‌شده هرکدام اضافه می‌شود.</p>
            </div>
        </div>

        <div x-data="{ lines: [{ cost_item_id: '', qty: 1, unit_price: '' }] }" class="mt-5">
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام خریداری‌شده</label>

            <template x-for="(line, idx) in lines" :key="idx">
                <div class="mb-2 grid grid-cols-12 items-center gap-2">
                    <select :name="`lines[${idx}][cost_item_id]`" required class="col-span-6 h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">انتخاب قلم…</option>
                        @foreach ($costItems as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}{{ $item->sku ? " ({$item->sku})" : '' }}</option>
                        @endforeach
                    </select>
                    <input type="number" :name="`lines[${idx}][qty]`" x-model.number="line.qty" min="1" placeholder="تعداد" required dir="ltr" class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <input type="number" :name="`lines[${idx}][unit_price]`" min="1" placeholder="قیمت واحد" required dir="ltr" class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <button type="button" @click="lines.length > 1 && lines.splice(idx, 1)" x-show="lines.length > 1" class="col-span-1 text-error-500 hover:underline">حذف</button>
                </div>
            </template>

            <button type="button" @click="lines.push({ cost_item_id: '', qty: 1, unit_price: '' })" class="mt-1 text-sm text-brand-500 hover:underline">+ افزودن قلم</button>
            @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت خرید</button>
        </div>
    </form>
</x-ui.modal>
@endsection
