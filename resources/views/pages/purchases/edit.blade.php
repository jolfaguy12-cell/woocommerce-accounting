@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'ویرایش فاکتور خرید #'.$invoice->id" parentLabel="ثبت خرید" :parentUrl="route('purchases.index')" />

<div x-data="{
        searchEndpoint: @js(route('purchases.items.search')),
        newLines: [],
        existingLineTotals: @js($invoice->lines->map(fn ($l, $i) => (int) old("lines.$i.qty", $l->qty) * (int) old("lines.$i.unit_price", $l->unit_price))->values()),
        shippingCostRaw: window.normalizeDigits(@js(old('shipping_cost', $invoice->shipping_cost))),
        ...window.purchaseInvoicePaymentFields(@js(old('payments', $invoice->pending_payments ?? []))),
        addLine() {
            this.newLines.push(window.makePurchaseLine());
        },
        removeLine(idx) {
            this.newLines.splice(idx, 1);
        },
        onShippingInput(event) {
            this.shippingCostRaw = window.normalizeDigits(event.target.value);
            event.target.value = this.display(this.shippingCostRaw);
        },
        fmtToman(n) {
            return `${this.display(String(Math.round(n)))} تومان`;
        },
        get subtotal() {
            return this.existingLineTotals.reduce((sum, v) => sum + (v || 0), 0)
                + this.newLines.reduce((sum, line) => sum + line.lineTotalValue, 0);
        },
        get finalTotal() {
            return this.subtotal + Number(this.shippingCostRaw || 0);
        },
        get remaining() {
            return this.finalTotal - this.paidAmount;
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
                    <input type="text" inputmode="numeric" dir="ltr" autocomplete="off"
                        x-bind:value="display(shippingCostRaw)" x-on:input="onShippingInput($event)"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <input type="hidden" name="shipping_cost" x-bind:value="shippingCostRaw">
                    @error('shipping_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-gray-400">دوباره بین همه اقلام تقسیم و بهای تمام‌شده هرکدام به‌روز می‌شود.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">افزودن تصاویر فاکتور (اختیاری، چندتایی)</label>
                    <input type="file" name="images[]" accept="image/*" multiple class="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('images')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-gray-400">برای حذف یا مدیریت تصاویر موجود، از صفحه نمایش فاکتور استفاده کنید.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">توضیحات کلی (اختیاری)</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">{{ old('notes', $invoice->notes) }}</textarea>
                    @error('notes')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام موجود</label>
                <p class="mb-2 text-xs text-gray-400">تعداد ردیفی که هنوز دریافت نشده را می‌توان آزادانه تغییر داد یا حذف کرد؛ تعداد ردیف دریافت‌شده فقط قابل افزایش است و قابل حذف نیست.</p>

                @foreach ($invoice->lines as $i => $line)
                    <div x-data="window.makeExistingPurchaseLine({{ (int) old("lines.$i.qty", $line->qty) }}, {{ (int) old("lines.$i.unit_price", $line->unit_price) }}, @js(old("lines.$i.note", $line->note)))"
                        x-effect="$root.existingLineTotals[{{ $i }}] = removed ? 0 : lineTotalValue"
                        :class="removed && 'opacity-40'" class="mb-2 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                        <input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}">
                        <input type="hidden" name="lines[{{ $i }}][_remove]" :value="removed ? 1 : 0">

                        <div class="col-span-3 text-sm text-gray-800 dark:text-white/90" :class="removed && 'line-through'">
                            {{ $line->product->name ?? $line->costItem->name }}
                            @if ($line->received_qty > 0)
                                <span class="block text-xs text-gray-400">{{ number_format($line->received_qty) }} عدد دریافت‌شده</span>
                            @endif
                        </div>

                        <div class="col-span-2">
                            <input type="text" inputmode="numeric" dir="ltr" required :disabled="removed"
                                x-bind:value="qty || ''" x-on:input="onQtyInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" name="lines[{{ $i }}][qty]" x-bind:value="qty" min="{{ max(1, $line->received_qty) }}">
                        </div>

                        <div class="col-span-3">
                            <input type="text" inputmode="numeric" dir="ltr" required :disabled="removed"
                                x-bind:value="display(unitPrice)" x-on:input="onUnitPriceInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" name="lines[{{ $i }}][unit_price]" x-bind:value="unitPrice">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="یا جمع ردیف" :disabled="removed"
                                x-bind:value="display(lineTotal)" x-on:input="onLineTotalInput($event)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 disabled:opacity-50 dark:border-gray-800 dark:bg-white/5 dark:text-gray-300">
                        </div>

                        <div class="col-span-2">
                            <button type="button" x-on:click="showNote = !showNote" class="text-xs text-brand-500 hover:underline" x-text="showNote ? 'بستن یادداشت' : 'افزودن یادداشت'"></button>
                            <template x-if="showNote">
                                <input type="text" name="lines[{{ $i }}][note]" x-model="note" :disabled="removed" placeholder="یادداشت"
                                    class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            </template>
                        </div>

                        <div class="col-span-2 text-left">
                            @if ($line->received_qty > 0)
                                <span class="text-xs text-gray-400">قابل حذف نیست</span>
                            @else
                                <button type="button" @click="removed = !removed" class="text-xs text-error-500 hover:underline" x-text="removed ? 'بازگردانی' : 'حذف'"></button>
                            @endif
                        </div>
                    </div>
                @endforeach

                <template x-for="(line, idx) in newLines" :key="idx">
                    <div class="mb-3 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-3 last:border-0 dark:border-gray-800">
                        <x-form.product-line-picker name-prefix="`lines[new${idx}]`" />

                        <div class="col-span-2">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="تعداد" required
                                x-bind:value="line.qty || ''" x-on:input="line.onQtyInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[new${idx}][qty]`" x-bind:value="line.qty">
                        </div>

                        <div class="col-span-3">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="قیمت واحد" required
                                x-bind:value="line.display(line.unitPrice)" x-on:input="line.onUnitPriceInput($event)"
                                class="h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <input type="hidden" :name="`lines[new${idx}][unit_price]`" x-bind:value="line.unitPrice">
                            <input type="text" inputmode="numeric" dir="ltr" placeholder="یا جمع ردیف"
                                x-bind:value="line.display(line.lineTotal)" x-on:input="line.onLineTotalInput($event)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-200 bg-gray-50 px-2 text-xs text-gray-600 dark:border-gray-800 dark:bg-white/5 dark:text-gray-300">
                        </div>

                        <div class="col-span-1 flex flex-col items-start gap-1">
                            <button type="button" x-on:click="line.showNote = !line.showNote" class="text-xs text-brand-500 hover:underline" x-text="line.showNote ? 'بستن یادداشت' : 'افزودن یادداشت'"></button>
                            <button type="button" @click="removeLine(idx)" class="text-xs text-error-500 hover:underline">حذف</button>
                        </div>

                        <div class="col-span-12" x-show="line.showNote" x-cloak>
                            <input type="text" :name="`lines[new${idx}][note]`" x-model="line.note" placeholder="یادداشت این ردیف (اختیاری)"
                                class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        </div>
                    </div>
                </template>

                <button type="button" @click="addLine()" class="mt-2 text-sm text-brand-500 hover:underline">+ افزودن ردیف جدید</button>
                @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">خلاصه فاکتور</h4>
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

            @if (! $invoice->journal_entry_id)
                <div>
                    <input type="hidden" name="payments_form" value="1">
                    <div class="mb-1.5 flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-400">پرداخت اولیه (اختیاری)</label>
                    </div>
                    <p class="mb-2 text-xs text-gray-400">این ردیف‌ها فقط با «ثبت نهایی» ثبت و از حساب کسر می‌شوند؛ تا آن زمان فقط روی همین پیش‌نویس ذخیره می‌مانند.</p>

                    <template x-for="(payment, idx) in payments" :key="idx">
                        <div class="mb-2 grid grid-cols-12 items-start gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                            <select :name="`payments[${idx}][bank_account_id]`" x-model="payment.bank_account_id" class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
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
            @endif

            <div class="flex justify-end gap-3">
                <a href="{{ route('purchases.show', $invoice) }}" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</a>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره تغییرات</button>
            </div>
        </form>
    </x-common.component-card>
</div>
@endsection
