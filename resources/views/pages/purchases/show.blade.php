@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="'فاکتور خرید #'.$invoice->id" parentLabel="ثبت خرید" :parentUrl="route('purchases.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @if ($errors->has('invoice_date'))
        <x-ui.alert variant="error" :message="$errors->first('invoice_date')" />
    @endif

    <x-common.component-card :title="'فاکتور خرید #'.$invoice->id">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">تامین‌کننده</p>
                    <a href="{{ route('suppliers.show', $invoice->supplier_party_id) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">{{ $invoice->supplier->name }}</a>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">شماره فاکتور</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $invoice->invoice_no ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">تاریخ خرید</p>
                    <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ \Morilog\Jalali\Jalalian::fromCarbon($invoice->invoice_date)->format('Y/m/d') }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                    <x-ui.badge :color="$invoice->status === 'received' ? 'success' : ($invoice->status === 'cancelled' ? 'error' : 'light')" size="sm">
                        {{ $statusLabels[$invoice->status] ?? $invoice->status }}
                    </x-ui.badge>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if (auth()->user()->hasRole(['admin', 'accountant']))
                    <a href="{{ route('purchases.edit', $invoice) }}" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ویرایش</a>
                    @if ($invoice->status === 'draft' || $invoice->status === 'partial')
                        <form method="POST" action="{{ route('purchases.finalize', $invoice) }}">
                            @csrf
                            <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">ثبت نهایی و صدور سند</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        @if ($invoice->notes)
            <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                <p class="text-xs text-gray-500 dark:text-gray-400">توضیحات کلی</p>
                <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $invoice->notes }}</p>
            </div>
        @endif
    </x-common.component-card>

    <x-common.component-card title="پرداخت‌ها">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">پرداخت‌شده هنگام ثبت این فاکتور</p>
                <x-tables.num :cell="false" class="mt-1 text-base font-medium" :value="$paidAtCreation->sum('amount')" type="toman" tone="positive" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">بدهی باقی‌مانده تامین‌کننده (کل)</p>
                <x-tables.num :cell="false" class="mt-1 text-base font-medium" :value="max(0, $supplierPayable)" type="toman" tone="negative" />
                <a href="{{ route('suppliers.show', $invoice->supplier_party_id) }}" class="mt-1 block text-theme-xs text-brand-500 hover:underline">مشاهده تاریخچه کامل پرداخت‌های تامین‌کننده</a>
            </div>
        </div>

        @if ($paidAtCreation->isNotEmpty())
            <div class="mt-4 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                @foreach ($paidAtCreation as $payment)
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">
                            {{ \Morilog\Jalali\Jalalian::fromCarbon($payment->paid_at)->format('Y/m/d') }}
                            @if ($payment->method)
                                — {{ ['bank_transfer' => 'انتقال بانکی', 'cash' => 'نقدی', 'card' => 'کارت به کارت', 'other' => 'سایر'][$payment->method] ?? $payment->method }}
                            @endif
                        </span>
                        <x-tables.num :cell="false" :value="$payment->amount" type="toman" />
                    </div>
                @endforeach
            </div>
        @endif

        <p class="mt-3 text-xs text-gray-400">بدهی تامین‌کننده مجموع همه فاکتورهاست، نه فقط این یکی — تسویه‌های بعدی از صفحه تامین‌کننده ثبت می‌شوند و همان‌جا و اینجا نمایش داده می‌شوند.</p>
    </x-common.component-card>

    @php
        $outstandingLines = $invoice->lines->filter(fn ($l) => $l->qty > $l->received_qty)->values();
        $returnableLines = $invoice->lines->filter(fn ($l) => $l->returnableQty() > 0)->values();
    @endphp

    @if ($outstandingLines->isNotEmpty() && $invoice->status !== 'cancelled')
        <x-common.component-card title="کالاهای باقی‌مانده (در انتظار دریافت)">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            <th class="py-2 text-right font-normal">کالا</th>
                            <th class="text-right font-normal">سفارش‌شده</th>
                            <th class="text-right font-normal">دریافت‌شده</th>
                            <th class="text-right font-normal">باقی‌مانده</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($outstandingLines as $line)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="py-2 text-gray-800 dark:text-white/90">{{ $line->product->name ?? $line->costItem->name }}</td>
                                <x-tables.num :value="$line->qty" tone="muted" />
                                <x-tables.num :value="$line->received_qty" tone="muted" />
                                <x-tables.num :value="$line->qty - $line->received_qty" class="font-medium" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" @click="$dispatch('open-receipt-modal')" class="mt-3 inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">
                ثبت دریافت کالا
            </button>
        </x-common.component-card>
    @endif

    @if ($invoice->receipts->isNotEmpty())
        <x-common.component-card title="تاریخچه دریافت کالا">
            <div class="space-y-3">
                @foreach ($invoice->receipts as $receipt)
                    <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <x-tables.ltr :cell="false" :value="\Morilog\Jalali\Jalalian::fromCarbon($receipt->received_at)->format('Y/m/d')" />
                            <span>ثبت‌کننده: {{ $receipt->creator->name ?? '—' }}</span>
                        </div>
                        <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                            @foreach ($receipt->lines as $receiptLine)
                                <li class="flex items-center justify-between gap-2">
                                    <span>
                                        {{ $receiptLine->invoiceLine->costItem->name }} —
                                        {{ number_format($receiptLine->qty) }} عدد
                                        @if ($receiptLine->package_count)
                                            ({{ number_format($receiptLine->package_count) }} {{ $receiptLine->package_label ?? 'بسته' }})
                                        @endif
                                        @if ($receiptLine->via_toggle)
                                            <span class="text-xs text-gray-400">(سوییچ سریع)</span>
                                        @endif
                                    </span>
                                    @if (auth()->user()->hasAnyRole(['admin', 'accountant', 'warehouse']) && $invoice->status !== 'cancelled')
                                        <button type="button" class="shrink-0 text-xs text-brand-500 hover:underline"
                                            @click="$dispatch('open-edit-receipt-line-modal', { id: {{ $receiptLine->id }}, name: @js($receiptLine->invoiceLine->costItem->name), qty: {{ $receiptLine->qty }} })">
                                            ویرایش
                                        </button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($receipt->notes)
                            <p class="mt-2 text-xs text-gray-400">{{ $receipt->notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-common.component-card>
    @endif

    @if (auth()->user()->hasRole(['admin', 'accountant']) && ($returnableLines->isNotEmpty() || $invoice->returns->isNotEmpty()))
        <x-common.component-card title="برگشت از خرید">
            @if ($invoice->returns->isNotEmpty())
                <div class="mb-3 space-y-3">
                    @foreach ($invoice->returns as $return)
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $return->reason }}</span>
                                <span>ثبت‌کننده: {{ $return->creator->name ?? '—' }}</span>
                            </div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($return->lines as $returnLine)
                                    <li>{{ $returnLine->invoiceLine->costItem->name }} — {{ number_format($returnLine->qty) }} عدد</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($returnableLines->isNotEmpty())
                <button type="button" @click="$dispatch('open-return-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    ثبت بازگشت به تامین‌کننده
                </button>
            @endif
        </x-common.component-card>
    @endif

    <x-common.component-card title="تصاویر فاکتور">
        <div class="flex flex-wrap gap-3">
            @forelse ($invoice->attachments as $attachment)
                <div class="relative w-28 rounded-lg border border-gray-200 p-2 text-center dark:border-gray-800">
                    <a href="{{ route('attachments.download', $attachment) }}" target="_blank" rel="noopener noreferrer" class="block text-2xl">📎</a>
                    <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</p>
                    @if (auth()->user()->hasRole(['admin', 'accountant']))
                        <form method="POST" action="{{ route('purchases.images.destroy', [$invoice, $attachment]) }}" onsubmit="return confirm('این تصویر حذف شود؟')" class="mt-1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-error-500 hover:underline">حذف</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">هنوز تصویری برای این فاکتور بارگذاری نشده است.</p>
            @endforelse
        </div>

        @if (auth()->user()->hasRole(['admin', 'accountant']))
            <form method="POST" action="{{ route('purchases.images.store', $invoice) }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="images[]" accept="image/*" multiple required
                    class="h-11 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <button type="submit" class="h-11 rounded-lg bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">افزودن تصویر</button>
                @error('images')<p class="text-xs text-error-500">{{ $message }}</p>@enderror
            </form>
        @endif
    </x-common.component-card>

    <x-common.component-card title="اقلام فاکتور">
        @php
            $canToggleOn = fn ($l) => $l->received_qty === 0 && $l->receiptLines->isEmpty();
            $canToggleOff = fn ($l) => $l->receiptLines->count() === 1 && $l->receiptLines->first()->via_toggle && $l->returned_qty === 0;
            $canToggle = auth()->user()->hasAnyRole(['admin', 'accountant', 'warehouse']) && $invoice->status !== 'cancelled';
        @endphp
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">کالا</th>
                        <th class="text-right font-normal">تعداد</th>
                        <th class="text-right font-normal">قیمت خرید (واحد)</th>
                        <th class="text-right font-normal">هزینه ارسال (واحد)</th>
                        <th class="text-right font-normal">بهای تمام‌شده (واحد)</th>
                        <th class="text-right font-normal">جمع ردیف</th>
                        <th class="text-right font-normal">توضیحات</th>
                        <th class="text-right font-normal">دریافت کامل</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->lines as $line)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90">
                                @if ($line->product)
                                    <a href="{{ route('products.show', $line->product) }}" target="_blank" rel="noopener noreferrer" class="text-brand-500 hover:underline">{{ $line->product->name }}</a>
                                @else
                                    {{ $line->costItem->name }}
                                @endif
                            </td>
                            <x-tables.num :value="$line->qty" tone="muted" />
                            <x-tables.num :value="$line->unit_price" tone="muted" />
                            <x-tables.num :value="$line->shipping_allocated" tone="muted" />
                            <x-tables.num class="font-medium" :value="$line->landed_unit_cost" />
                            <x-tables.num :value="$line->qty * $line->unit_price" tone="muted" />
                            <td class="text-gray-500 dark:text-gray-400">{{ $line->note ?? '—' }}</td>
                            <td class="py-2">
                                @if ($canToggle && ($line->received_qty === 0 ? $canToggleOn($line) : ($line->received_qty >= $line->qty && $canToggleOff($line))))
                                    <form method="POST" action="{{ route('purchases.lines.toggle', [$invoice, $line]) }}"
                                        @if ($line->received_qty > 0) onsubmit="return confirm('علامت دریافت این ردیف بازگردانده شود؟')" @endif>
                                        @csrf
                                        <x-ui.toggle-switch name="received" :checked="$line->received_qty > 0" />
                                    </form>
                                @else
                                    <x-ui.toggle-switch name="received" :checked="$line->received_qty > 0" :disabled="true" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 font-medium text-gray-800 dark:border-gray-700 dark:text-white/90">
                        <td colspan="5" class="py-2 text-left">جمع کالا:</td>
                        <x-tables.num  :value="$invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price)" type="toman" />
                        <td></td>
                        <td></td>
                    </tr>
                    <tr class="text-gray-600 dark:text-gray-300">
                        <td colspan="5" class="py-1 text-left">هزینه حمل:</td>
                        <x-tables.num  :value="$invoice->shipping_cost" type="toman" />
                        <td></td>
                        <td></td>
                    </tr>
                    <tr class="text-lg font-bold text-gray-800 dark:text-white/90">
                        <td colspan="5" class="py-1 text-left">مبلغ کل فاکتور:</td>
                        <x-tables.num  :value="$invoice->lines->sum(fn ($l) => $l->qty * $l->unit_price) + $invoice->shipping_cost" type="toman" />
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-400">
            «بهای تمام‌شده» همان قیمتی است که در محاسبه سود سفارش‌ها استفاده می‌شود (قیمت خرید + سهم هزینه ارسال). سفارش‌هایی که سودشان قبلاً نهایی شده با اصلاح این فاکتور دوباره محاسبه نمی‌شوند؛ فقط سفارش‌های در انتظار بهای تمام‌شده به‌روز می‌شوند.
        </p>
    </x-common.component-card>
</div>

@if ($outstandingLines->isNotEmpty() && $invoice->status !== 'cancelled')
    <div x-data="{ open: false }" @open-receipt-modal.window="open = true">
        <x-ui.modal :isOpen="$errors->has('lines') && old('received_at')" @open-receipt-modal.window="open = true" class="max-w-2xl p-6">
            <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت دریافت کالا</h4>

            <form method="POST" action="{{ route('purchases.receipts.store', $invoice) }}" class="space-y-4">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تاریخ دریافت</label>
                        <input type="text" inputmode="none" autocomplete="off" data-jdp value="{{ old('received_at') ? \Morilog\Jalali\Jalalian::fromCarbon(\Illuminate\Support\Carbon::parse(old('received_at')))->format('Y/m/d') : \Morilog\Jalali\Jalalian::now()->format('Y/m/d') }}"
                            data-jdp-target-value-input="#receipt-date-g" data-jdp-target-value-type="gregorian"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <input type="hidden" id="receipt-date-g" name="received_at" value="{{ old('received_at', now()->toDateString()) }}">
                        @error('received_at')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">یادداشت (اختیاری)</label>
                        <input type="text" name="notes" value="{{ old('notes') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام دریافت‌شده در این محموله</label>
                    <div class="space-y-2">
                        @foreach ($outstandingLines as $line)
                            <div class="grid grid-cols-12 items-center gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                                <div class="col-span-4 text-sm text-gray-800 dark:text-white/90">
                                    {{ $line->product->name ?? $line->costItem->name }}
                                    <span class="block text-xs text-gray-400">باقی‌مانده: {{ number_format($line->qty - $line->received_qty) }}</span>
                                </div>
                                <input type="number" name="lines[{{ $line->id }}][qty]" min="0" max="{{ $line->qty - $line->received_qty }}" placeholder="تعداد" dir="ltr"
                                    class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                <input type="number" name="lines[{{ $line->id }}][package_count]" min="1" placeholder="تعداد بسته" dir="ltr"
                                    class="col-span-2 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                <input type="text" name="lines[{{ $line->id }}][package_label]" placeholder="نوع بسته (مثلاً کارتن)"
                                    class="col-span-3 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            </div>
                        @endforeach
                    </div>
                    @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                    <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت دریافت</button>
                </div>
            </form>
        </x-ui.modal>
    </div>
@endif

@if (auth()->user()->hasAnyRole(['admin', 'accountant', 'warehouse']))
    <div x-data="{ open: false, receiptLineId: null, itemName: '', qty: 0 }"
        @open-edit-receipt-line-modal.window="open = true; receiptLineId = $event.detail.id; itemName = $event.detail.name; qty = $event.detail.qty">
        <x-ui.modal :isOpen="$errors->has('lines') && old('reason') === null && old('qty') !== null" @open-edit-receipt-line-modal.window="open = true" class="max-w-md p-6">
            <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ویرایش تعداد دریافتی — <span x-text="itemName"></span></h4>

            <form method="POST" x-bind:action="'{{ route('purchases.receipt-lines.update', [$invoice, '__ID__']) }}'.replace('__ID__', receiptLineId)" class="space-y-4">
                @csrf
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">تعداد جدید</label>
                    <input type="number" name="qty" min="0" required dir="ltr" x-model.number="qty"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <p class="mt-1 text-xs text-gray-400">صفر یعنی این محموله اشتباه ثبت شده و کاملاً حذف می‌شود.</p>
                    @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">دلیل اصلاح</label>
                    <input type="text" name="reason" required maxlength="255"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                    <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت اصلاح</button>
                </div>
            </form>
        </x-ui.modal>
    </div>
@endif

@if (auth()->user()->hasRole(['admin', 'accountant']) && $returnableLines->isNotEmpty())
    <div x-data="{ open: false }" @open-return-modal.window="open = true">
        <x-ui.modal :isOpen="$errors->has('reason')" @open-return-modal.window="open = true" class="max-w-2xl p-6">
            <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت بازگشت به تامین‌کننده</h4>

            <form method="POST" action="{{ route('purchases.returns.store', $invoice) }}" class="space-y-4">
                @csrf

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">دلیل بازگشت</label>
                    <input type="text" name="reason" required value="{{ old('reason') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    @error('reason')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">اقلام بازگشتی</label>
                    <div class="space-y-2">
                        @foreach ($returnableLines as $line)
                            <div class="grid grid-cols-12 items-center gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                                <div class="col-span-8 text-sm text-gray-800 dark:text-white/90">
                                    {{ $line->product->name ?? $line->costItem->name }}
                                    <span class="block text-xs text-gray-400">قابل بازگشت: {{ number_format($line->returnableQty()) }}</span>
                                </div>
                                <input type="number" name="lines[{{ $line->id }}][qty]" min="0" max="{{ $line->returnableQty() }}" placeholder="تعداد" dir="ltr"
                                    class="col-span-4 h-10 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            </div>
                        @endforeach
                    </div>
                    @error('lines')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                    <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت بازگشت</button>
                </div>
            </form>
        </x-ui.modal>
    </div>
@endif
@endsection
