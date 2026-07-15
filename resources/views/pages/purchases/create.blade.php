@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="خرید جدید" />

<div x-data="purchaseInvoiceForm({ searchEndpoint: @js(route('purchases.items.search')), shippingCost: @js(old('shipping_cost', 0)) })">
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
                    <input type="text" inputmode="numeric" dir="ltr" autocomplete="off"
                        x-bind:value="display(shippingCostRaw)" x-on:input="onShippingInput($event)"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <input type="hidden" name="shipping_cost" x-bind:value="shippingCostRaw">
                    @error('shipping_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-gray-400">اگر بعداً مشخص شد، از صفحه فاکتور می‌توانید ویرایشش کنید — به‌طور خودکار بین اقلام تقسیم و به بهای تمام‌شده اضافه می‌شود.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تصاویر فاکتور (اختیاری، چندتایی)</label>
                    <input type="file" name="images[]" accept="image/*" multiple class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('images')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">توضیحات کلی (اختیاری)</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">{{ old('notes') }}</textarea>
                    @error('notes')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام خریداری‌شده</label>

                <template x-for="(line, idx) in lines" :key="idx">
                    <div class="mb-3 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800">
                        <x-form.product-line-picker name-prefix="`lines[${idx}]`" />

                        <div class="col-span-2">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="تعداد" required
                                x-bind:value="line.qty || ''" x-on:input="line.onQtyInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[${idx}][qty]`" x-bind:value="line.qty">
                        </div>

                        <div class="col-span-3">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="قیمت واحد" required
                                x-bind:value="line.display(line.unitPrice)" x-on:input="line.onUnitPriceInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[${idx}][unit_price]`" x-bind:value="line.unitPrice">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="یا جمع ردیف"
                                x-bind:value="line.display(line.lineTotal)" x-on:input="line.onLineTotalInput($event)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 dark:border-gray-800 dark:bg-white/5 dark:text-gray-300">
                        </div>

                        <div class="col-span-1 flex flex-col items-start gap-1">
                            <button type="button" x-on:click="line.showNote = !line.showNote" class="text-xs text-brand-500 hover:underline" x-text="line.showNote ? 'بستن یادداشت' : 'افزودن یادداشت'"></button>
                            <button type="button" x-on:click="removeLine(idx)" x-show="lines.length > 1" class="text-xs text-error-500 hover:underline">حذف</button>
                        </div>

                        <div class="col-span-12" x-show="line.showNote" x-cloak>
                            <input type="text" :name="`lines[${idx}][note]`" x-model="line.note" placeholder="یادداشت این ردیف (اختیاری)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        </div>
                    </div>
                </template>

                <button type="button" @click="addLine()" class="mt-1 text-sm text-brand-500 hover:underline">+ افزودن قلم</button>
                @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">خلاصه فاکتور</h4>
                {{--
                    <x-tables.num> can't be used for these — it renders once,
                    server-side, and these totals recompute on every keystroke.
                    The spans below replicate its contract by hand: dir="ltr" +
                    tabular figures + the same tone classes it uses internally.
                --}}
                <dl class="grid gap-2 text-sm sm:max-w-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">جمع اقلام</dt>
                        <dd dir="ltr" class="tabular-fig text-gray-800 dark:text-white/90" x-text="fmtToman(subtotal)"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">هزینه حمل</dt>
                        <dd dir="ltr" class="tabular-fig text-gray-800 dark:text-white/90" x-text="fmtToman(Number(shippingCostRaw || 0))"></dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-100 pt-2 font-semibold text-gray-800 dark:border-gray-800 dark:text-white/90">
                        <dt>مبلغ کل فاکتور</dt>
                        <dd dir="ltr" class="tabular-fig" x-text="fmtToman(finalTotal)"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">پرداخت‌شده (اولیه)</dt>
                        <dd dir="ltr" class="tabular-fig text-success-700 dark:text-success-400" x-text="fmtToman(paidAmount)"></dd>
                    </div>
                    <div class="flex items-center justify-between font-semibold">
                        <dt class="text-gray-500 dark:text-gray-400">مانده قابل پرداخت</dt>
                        <dd dir="ltr" class="tabular-fig text-error-600 dark:text-error-400" x-text="fmtToman(remaining)"></dd>
                    </div>
                </dl>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-400">پرداخت اولیه (اختیاری)</label>
                </div>
                <p class="mb-2 text-xs text-gray-400">این ردیف‌ها فقط با «ثبت نهایی» ثبت و از حساب کسر می‌شوند؛ با «ذخیره پیش‌نویس» فقط ذخیره می‌مانند تا بعداً از ویرایش فاکتور تکمیل یا نهایی‌شان کنید.</p>

                <template x-for="(payment, idx) in payments" :key="idx">
                    <div class="mb-2 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                        <select :name="`payments[${idx}][bank_account_id]`" class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="">حساب…</option>
                            @foreach ($bankAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>

                        <div class="col-span-2">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="مبلغ"
                                x-bind:value="display(payment.amountRaw)" x-on:input="onPaymentAmountInput(payment, $event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`payments[${idx}][amount]`" x-bind:value="payment.amountRaw">
                        </div>

                        <select :name="`payments[${idx}][method]`" x-model="payment.method" class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="">روش…</option>
                            @foreach (['bank_transfer' => 'انتقال بانکی', 'cash' => 'نقدی', 'card' => 'کارت به کارت', 'other' => 'سایر'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        <input type="text" :name="`payments[${idx}][reference]`" x-model="payment.reference" placeholder="شماره پیگیری" dir="ltr"
                            class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

                        <input type="text" :name="`payments[${idx}][note]`" x-model="payment.note" placeholder="یادداشت"
                            class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

                        <button type="button" @click="removePayment(idx)" class="col-span-1 text-xs text-error-500 hover:underline">حذف</button>
                    </div>
                </template>

                <button type="button" @click="addPayment()" class="mt-1 text-sm text-brand-500 hover:underline">+ افزودن پرداخت</button>
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
