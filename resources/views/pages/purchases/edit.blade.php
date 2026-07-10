@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'ویرایش فاکتور خرید #'.$invoice->id" />

<div x-data="{
        newLines: [],
        searchTimer: null,
        addLine() {
            this.newLines.push({ product_mirror_id: '', product_name: '', new_item_name: '', showNew: false, results: [], qty: 1, unit_price: '', note: '' });
        },
        removeLine(idx) {
            this.newLines.splice(idx, 1);
        },
        search(line, query) {
            line.product_name = query;
            line.product_mirror_id = '';
            clearTimeout(this.searchTimer);
            if (query.length < 2) { line.results = []; return; }
            this.searchTimer = setTimeout(() => {
                fetch('{{ route('purchases.items.search') }}?q=' + encodeURIComponent(query))
                    .then(r => r.json())
                    .then(data => { line.results = data; });
            }, 250);
        },
        pick(line, item) {
            line.product_mirror_id = item.id;
            line.product_name = item.name + (item.sku ? ' (' + item.sku + ')' : '');
            line.results = [];
        },
    }">
    @if ($invoice->journal_entry_id)
        <x-ui.alert variant="warning" message="این فاکتور قبلاً دریافت و سند حسابداری آن صادر شده است. ویرایش هزینه حمل یا قیمت‌ها، سند قبلی را برگشت می‌زند و سند اصلاحی جدید صادر می‌کند." />
    @endif

    <x-common.component-card :title="'ویرایش فاکتور خرید #'.$invoice->id">
        <form method="POST" action="{{ route('purchases.update', $invoice) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            @method('PUT')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره فاکتور (اختیاری)</label>
                    <input type="text" name="invoice_no" dir="ltr" value="{{ old('invoice_no', $invoice->invoice_no) }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('invoice_no')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تاریخ خرید</label>
                    <input type="text" inputmode="none" autocomplete="off" data-jdp value="{{ \Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d') }}"
                        data-jdp-target-value-input="#purchase-invoice-date-g" data-jdp-target-value-type="gregorian"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <input type="hidden" id="purchase-invoice-date-g" name="invoice_date" value="{{ old('invoice_date', $invoice->invoice_date->toDateString()) }}">
                    @error('invoice_date')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">هزینه حمل کل فاکتور</label>
                    <input type="number" name="shipping_cost" min="0" dir="ltr" value="{{ old('shipping_cost', $invoice->shipping_cost) }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('shipping_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-gray-400">دوباره بین همه اقلام تقسیم و بهای تمام‌شده هرکدام به‌روز می‌شود.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تصویر فاکتور (اختیاری)</label>
                    <input type="file" name="image" accept="image/*" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('image')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام موجود</label>
                <p class="mb-2 text-xs text-gray-400">فقط قیمت و توضیحات هر ردیف قابل ویرایش است؛ برای تغییر تعداد یا کالا، یک ردیف جدید اضافه کنید.</p>

                @foreach ($invoice->lines as $i => $line)
                    <div class="mb-2 grid grid-cols-12 items-center gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                        <input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}">
                        <div class="col-span-4 text-sm text-gray-800 dark:text-white/90">{{ $line->product->name ?? $line->costItem->name }}</div>
                        <div class="col-span-2 text-sm text-gray-600 dark:text-gray-300" dir="ltr">{{ number_format($line->qty) }} عدد</div>
                        <input type="number" name="lines[{{ $i }}][unit_price]" min="1" required dir="ltr" value="{{ old("lines.$i.unit_price", $line->unit_price) }}"
                            class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <input type="text" name="lines[{{ $i }}][note]" value="{{ old("lines.$i.note", $line->note) }}" placeholder="توضیحات"
                            class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    </div>
                @endforeach

                <template x-for="(line, idx) in newLines" :key="idx">
                    <div class="mb-3 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800">
                        <div class="relative col-span-6">
                            <input type="text" x-model="line.product_name" @input="search(line, $event.target.value)"
                                placeholder="نام کالای جدید را برای جستجو تایپ کنید…" autocomplete="off"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[new${idx}][product_mirror_id]`" :value="line.product_mirror_id">

                            <div x-show="line.results.length > 0" x-cloak @click.outside="line.results = []"
                                class="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                <template x-for="item in line.results" :key="item.id">
                                    <button type="button" @click="pick(line, item)" class="block w-full px-3 py-2 text-right text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span x-text="item.name"></span>
                                    </button>
                                </template>
                            </div>

                            <button type="button" @click="line.showNew = !line.showNew; line.product_mirror_id = ''" class="mt-1 text-xs text-brand-500 hover:underline" x-text="line.showNew ? 'جستجوی کالای موجود' : 'کالای من در فهرست نیست…'"></button>
                            <input x-show="line.showNew" :name="`lines[new${idx}][new_item_name]`" type="text" placeholder="نام کالای جدید"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input :name="`lines[new${idx}][note]`" type="text" placeholder="توضیحات (اختیاری)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        </div>
                        <input type="number" :name="`lines[new${idx}][qty]`" x-model.number="line.qty" min="1" placeholder="تعداد" required dir="ltr" class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <input type="number" :name="`lines[new${idx}][unit_price]`" min="1" placeholder="قیمت واحد" required dir="ltr" class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <button type="button" @click="removeLine(idx)" class="col-span-1 text-error-500 hover:underline">حذف</button>
                    </div>
                </template>

                <button type="button" @click="addLine()" class="mt-2 text-sm text-brand-500 hover:underline">+ افزودن ردیف جدید</button>
                @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('purchases.show', $invoice) }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</a>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره تغییرات</button>
            </div>
        </form>
    </x-common.component-card>
</div>
@endsection
