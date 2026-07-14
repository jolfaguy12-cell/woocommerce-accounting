@extends('layouts.app')

{{--
    «ثبت حقوق دوره» — the accrual, and an optional «پرداخت هم‌زمان» alongside it.

    The accrual ALWAYS happens: it posts Dr salary expense / Cr each employee's
    payroll payable, and asks for no bank account at all — no cash moves here, so
    none is needed. The payable line carries the employee's party_id; without it
    the entry still balances and every individual «مانده حقوق» reads zero.

    «پرداخت هم‌زمان» is additive, per employee, and optional. Enabling it for a
    row posts a SEPARATE payment entry (Dr that employee's payable, Cr the chosen
    account) in the SAME request as the accrual — atomic, because both live inside
    one database transaction on the server (PayrollService::post()): if the
    payment fails, the accrual it would have ridden along with never posts either.
    It is still two entries, never one, and it can still be full or partial —
    enabling it does not require paying the whole net.
--}}
@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت حقوق دوره" parentLabel="لیست‌های حقوق" :parentUrl="route('payroll.index')" />

<div class="space-y-4">
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    @if ($employees->isEmpty())
        <x-states.state variant="empty"
            title="هیچ کارمند فعالی وجود ندارد"
            message="کارمند، یک طرف حساب با نقش «کارمند» است. ابتدا از صفحهٔ طرف حساب‌ها این نقش را فعال کنید." />
    @else
    <form method="POST" action="{{ route('payroll.store') }}"
          x-data="{
            rows: {{ Illuminate\Support\Js::from($employees->mapWithKeys(fn ($e) => [$e['id'] => [
                'selected' => ! $e['already_accrued'],
                'gross' => $e['base_salary'],
                'advance' => 0,
                'payNow' => false,
            ]])) }},
            money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
            net(id) { return Math.max(0, (parseInt(this.rows[id].gross || 0, 10) || 0) - (parseInt(this.rows[id].advance || 0, 10) || 0)); },
            get totalGross() { return Object.entries(this.rows).filter(([, r]) => r.selected).reduce((s, [, r]) => s + (parseInt(r.gross || 0, 10) || 0), 0); },
            get totalNet() { return Object.entries(this.rows).filter(([id, r]) => r.selected).reduce((s, [id]) => s + this.net(id), 0); },
            // Excluding a row must also drop any «پرداخت هم‌زمان» riding on it — an
            // employee removed from this run cannot still be paid alongside it.
            deselect(id) { this.rows[id].selected = false; this.rows[id].payNow = false; },
          }"
          class="space-y-4">
        @csrf

        <x-common.component-card title="دوره">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">دوره حقوقی</label>
                    <select name="jalali_period" class="{{ $selectClass }}"
                            onchange="window.location = '{{ route('payroll.create') }}?period=' + this.value">
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}" @selected($value === $period)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">توضیح</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" class="{{ $inputClass }}">
                </div>
            </div>
        </x-common.component-card>

        <x-common.component-card title="کارکنان این دوره"
            desc="«حقوق ناخالص» پیشنهاد اولیه از حقوق پایه است و قابل تغییر است. «کسر مساعده» فقط تا سقف مساعده‌ای که کارمند در دست دارد پذیرفته می‌شود. «پرداخت هم‌زمان» اختیاری است و کامل یا جزئی مجاز است.">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3 font-medium"></th>
                            <th class="px-3 py-3 font-medium">کارمند</th>
                            <th class="px-3 py-3 font-medium">حقوق ناخالص</th>
                            <th class="px-3 py-3 font-medium">کسر مساعده</th>
                            <th class="px-3 py-3 font-medium">خالص پرداختنی</th>
                            <th class="px-3 py-3 font-medium">پرداخت هم‌زمان</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($employees as $i => $e)
                            @php($rowId = $e['id'])
                            <tr @class(['opacity-60' => $e['already_accrued']])>
                                <td class="px-3 py-3">
                                    <input type="checkbox" x-model="rows[{{ $rowId }}].selected"
                                        @change="if (! rows[{{ $rowId }}].selected) rows[{{ $rowId }}].payNow = false"
                                        @disabled($e['already_accrued'])
                                        class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                                </td>
                                <td class="px-3 py-3">
                                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $e['name'] }}</p>
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $e['job_title'] ?? '—' }}
                                        @if ($e['advance_held'] > 0)
                                            · مساعده در دست: {{ number_format($e['advance_held']) }} تومان
                                        @endif
                                    </p>
                                    @if ($e['already_accrued'])
                                        <p class="mt-1 text-theme-xs text-warning-600 dark:text-warning-400">
                                            حقوق این دوره قبلاً ثبت شده است.
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <template x-if="rows[{{ $rowId }}].selected">
                                        <input type="hidden" name="items[{{ $i }}][employee_id]" value="{{ $rowId }}">
                                    </template>
                                    <input type="number" min="1" dir="ltr"
                                        :name="rows[{{ $rowId }}].selected ? 'items[{{ $i }}][gross]' : ''"
                                        x-model="rows[{{ $rowId }}].gross"
                                        :disabled="! rows[{{ $rowId }}].selected"
                                        class="{{ $inputClass }} max-w-40">
                                </td>
                                <td class="px-3 py-3">
                                    <input type="number" min="0" max="{{ $e['advance_held'] }}" dir="ltr"
                                        :name="rows[{{ $rowId }}].selected ? 'items[{{ $i }}][advances_deducted]' : ''"
                                        x-model="rows[{{ $rowId }}].advance"
                                        :disabled="! rows[{{ $rowId }}].selected || {{ $e['advance_held'] }} === 0"
                                        class="{{ $inputClass }} max-w-40">
                                </td>
                                <td class="px-3 py-3">
                                    <span dir="ltr" class="block text-right text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90"
                                          x-text="money(net({{ $rowId }})) + ' تومان'"></span>
                                </td>
                                <td class="px-3 py-3">
                                    <input type="checkbox" x-model="rows[{{ $rowId }}].payNow"
                                        name="items[{{ $i }}][pay_now]" value="1"
                                        :disabled="! rows[{{ $rowId }}].selected"
                                        class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                                </td>
                            </tr>
                            {{--
                                The «پرداخت هم‌زمان» sub-form for this row, folded out below
                                it. It stays in the DOM (Alpine x-show, not @if) so the Jalali
                                picker's watcher and the money-input's own Alpine scope both
                                initialise normally regardless of whether the row starts open.
                            --}}
                            <tr x-show="rows[{{ $rowId }}].selected && rows[{{ $rowId }}].payNow" x-cloak>
                                <td></td>
                                <td colspan="5" class="px-3 pb-4">
                                    <div class="grid gap-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-white/[0.02] sm:grid-cols-3">
                                        <x-form.money-input :name="'items['.$i.'][payment_amount]'" label="مبلغ پرداخت" />
                                        <div>
                                            <label class="{{ $labelClass }}">حساب پرداخت‌کننده</label>
                                            <select name="items[{{ $i }}][payment_bank_account_id]" class="{{ $selectClass }}">
                                                <option value="">انتخاب کنید…</option>
                                                @foreach ($banks as $bank)
                                                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <x-form.jalali-date :name="'items['.$i.'][payment_date]'" label="تاریخ پرداخت" :value="$today" />
                                        <div>
                                            <label class="{{ $labelClass }}">روش پرداخت</label>
                                            <select name="items[{{ $i }}][payment_method]" class="{{ $selectClass }}">
                                                <option value="">انتخاب کنید…</option>
                                                @foreach ($methods as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">شماره پیگیری</label>
                                            <input type="text" name="items[{{ $i }}][payment_reference]" dir="ltr" class="{{ $inputClass }}">
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="{{ $labelClass }}">توضیحات</label>
                                            <input type="text" name="items[{{ $i }}][payment_note]" class="{{ $inputClass }}">
                                        </div>
                                    </div>
                                    <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                                        حداکثر قابل پرداخت هم‌زمان برای این کارمند، خالص همین دوره است: <span x-text="money(net({{ $rowId }})) + ' تومان'"></span>. پرداخت کامل یا جزئی هر دو مجاز است.
                                    </p>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-gray-200 dark:border-gray-700">
                        <tr>
                            <td colspan="2" class="px-3 py-3 text-theme-sm font-medium text-gray-700 dark:text-gray-300">جمع</td>
                            <td class="px-3 py-3">
                                <span dir="ltr" class="block text-right text-theme-sm font-semibold tabular-nums text-gray-800 dark:text-white/90"
                                      x-text="money(totalGross) + ' تومان'"></span>
                            </td>
                            <td></td>
                            <td class="px-3 py-3">
                                <span dir="ltr" class="block text-right text-theme-sm font-semibold tabular-nums text-gray-800 dark:text-white/90"
                                      x-text="money(totalNet) + ' تومان'"></span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-4 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                با ثبت این لیست، حقوق هر کارمند «تحقق می‌یابد» و در «مانده حقوق» او می‌نشیند — بدون «پرداخت هم‌زمان»، هیچ وجهی جابه‌جا نمی‌شود.
                «پرداخت هم‌زمان» را فقط برای کارمندانی فعال کنید که همین حالا هم به آن‌ها پرداخت می‌کنید؛ می‌توانید بعداً هم از صفحهٔ همان کارمند «پرداخت حقوق» را جداگانه ثبت کنید.
            </p>

            <div class="mt-4">
                <button type="submit"
                    class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                    ثبت حقوق دوره
                </button>
            </div>
        </x-common.component-card>
    </form>
    @endif
</div>
@endsection
