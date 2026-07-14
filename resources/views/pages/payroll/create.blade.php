@extends('layouts.app')

{{--
    «ثبت حقوق دوره» — accrual only.

    This posts Dr salary expense / Cr each employee's payroll payable. No cash moves:
    the salary is EARNED here, and it is PAID from the employee's own page. A month
    where the first happened and the second has not is the normal case.

    The payable line carries the employee's party_id. Without it the entry still
    balances and every individual «مانده حقوق» reads zero — a debt the company owes
    to nobody in particular, which cannot be paid, disputed or reconciled.
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
            ]])) }},
            money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
            net(id) { return Math.max(0, (parseInt(this.rows[id].gross || 0, 10) || 0) - (parseInt(this.rows[id].advance || 0, 10) || 0)); },
            get totalGross() { return Object.entries(this.rows).filter(([, r]) => r.selected).reduce((s, [, r]) => s + (parseInt(r.gross || 0, 10) || 0), 0); },
            get totalNet() { return Object.entries(this.rows).filter(([id, r]) => r.selected).reduce((s, [id]) => s + this.net(id), 0); },
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
            desc="«حقوق ناخالص» پیشنهاد اولیه از حقوق پایه است و قابل تغییر است. «کسر مساعده» فقط تا سقف مساعده‌ای که کارمند در دست دارد پذیرفته می‌شود.">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-3 font-medium"></th>
                            <th class="px-3 py-3 font-medium">کارمند</th>
                            <th class="px-3 py-3 font-medium">حقوق ناخالص</th>
                            <th class="px-3 py-3 font-medium">کسر مساعده</th>
                            <th class="px-3 py-3 font-medium">خالص پرداختنی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($employees as $i => $e)
                            <tr @class(['opacity-60' => $e['already_accrued']])>
                                <td class="px-3 py-3">
                                    <input type="checkbox" x-model="rows[{{ $e['id'] }}].selected"
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
                                    <template x-if="rows[{{ $e['id'] }}].selected">
                                        <input type="hidden" name="items[{{ $i }}][employee_id]" value="{{ $e['id'] }}">
                                    </template>
                                    <input type="number" min="1" dir="ltr"
                                        :name="rows[{{ $e['id'] }}].selected ? 'items[{{ $i }}][gross]' : ''"
                                        x-model="rows[{{ $e['id'] }}].gross"
                                        :disabled="! rows[{{ $e['id'] }}].selected"
                                        class="{{ $inputClass }} max-w-40">
                                </td>
                                <td class="px-3 py-3">
                                    <input type="number" min="0" max="{{ $e['advance_held'] }}" dir="ltr"
                                        :name="rows[{{ $e['id'] }}].selected ? 'items[{{ $i }}][advances_deducted]' : ''"
                                        x-model="rows[{{ $e['id'] }}].advance"
                                        :disabled="! rows[{{ $e['id'] }}].selected || {{ $e['advance_held'] }} === 0"
                                        class="{{ $inputClass }} max-w-40">
                                </td>
                                <td class="px-3 py-3">
                                    <span dir="ltr" class="block text-right text-theme-sm font-medium tabular-nums text-gray-800 dark:text-white/90"
                                          x-text="money(net({{ $e['id'] }})) + ' تومان'"></span>
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
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-4 rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                با ثبت این لیست، هیچ وجهی جابه‌جا نمی‌شود: حقوق «تحقق می‌یابد» و در «مانده حقوق» هر کارمند می‌نشیند.
                پرداخت آن، عملیات جداگانه‌ای است و از صفحهٔ همان کارمند انجام می‌شود.
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
