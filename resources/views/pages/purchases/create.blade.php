@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="خرید جدید" />

<div x-data="{
        lines: [{ product_mirror_id: '', product_name: '', new_item_name: '', showNew: false, results: [], qty: 1, unit_price: '', note: '' }],
        addLine() {
            this.lines.push({ product_mirror_id: '', product_name: '', new_item_name: '', showNew: false, results: [], qty: 1, unit_price: '', note: '' });
        },
        removeLine(idx) {
            if (this.lines.length > 1) this.lines.splice(idx, 1);
        },
        searchTimer: null,
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
    <x-common.component-card title="خرید جدید">
        <form method="POST" action="{{ route('purchases.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تامین‌کننده</label>
                    <select name="supplier_party_id" required onchange="document.getElementById('new-purchase-supplier-name-wrap').classList.toggle('hidden', this.value !== '__new__')"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}" @selected(old('supplier_party_id', $preselectedSupplierId) == $s->id)>{{ $s->name }}{{ $s->shop_name ? " ({$s->shop_name})" : '' }}</option>
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
                    <p class="mt-1 text-xs text-gray-400">اگر بعداً مشخص شد، از صفحه فاکتور می‌توانید ویرایشش کنید — به‌طور خودکار بین اقلام تقسیم و به بهای تمام‌شده اضافه می‌شود.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تصاویر فاکتور (اختیاری، چندتایی)</label>
                    <input type="file" name="images[]" accept="image/*" multiple class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('images')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام خریداری‌شده</label>

                <template x-for="(line, idx) in lines" :key="idx">
                    <div class="mb-3 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800">
                        <div class="relative col-span-6">
                            <input type="text" x-model="line.product_name" @input="search(line, $event.target.value)" @focus="showNew = false"
                                placeholder="نام کالا را برای جستجو تایپ کنید…" autocomplete="off" dir="rtl"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[${idx}][product_mirror_id]`" :value="line.product_mirror_id">

                            <div x-show="line.results.length > 0" x-cloak @click.outside="line.results = []"
                                class="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                <template x-for="item in line.results" :key="item.id">
                                    <button type="button" @click="pick(line, item)"
                                        class="block w-full px-3 py-2 text-right text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span x-text="item.name"></span>
                                        <span x-show="item.sku" class="text-xs text-gray-400" x-text="item.sku ? '(' + item.sku + ')' : ''"></span>
                                    </button>
                                </template>
                            </div>

                            <button type="button" @click="line.showNew = !line.showNew; line.product_mirror_id = ''"
                                class="mt-1 text-xs text-brand-500 hover:underline" x-text="line.showNew ? 'جستجوی کالای موجود' : 'کالای من در فهرست نیست…'"></button>

                            <input x-show="line.showNew" :name="`lines[${idx}][new_item_name]`" type="text" placeholder="نام کالای جدید (مثلاً بسته‌بندی)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

                            <input :name="`lines[${idx}][note]`" type="text" placeholder="توضیحات (اختیاری)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        </div>
                        <input type="number" :name="`lines[${idx}][qty]`" x-model.number="line.qty" min="1" placeholder="تعداد" required dir="ltr" class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <input type="number" :name="`lines[${idx}][unit_price]`" min="1" placeholder="قیمت واحد" required dir="ltr" class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <button type="button" @click="removeLine(idx)" x-show="lines.length > 1" class="col-span-1 text-error-500 hover:underline">حذف</button>
                    </div>
                </template>

                <button type="button" @click="addLine()" class="mt-1 text-sm text-brand-500 hover:underline">+ افزودن قلم</button>
                @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('purchases.index') }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</a>
                <button type="submit" name="action" value="draft" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ذخیره پیش‌نویس</button>
                <button type="submit" name="action" value="finalize" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت نهایی و صدور سند</button>
            </div>
        </form>
    </x-common.component-card>
</div>
@endsection
