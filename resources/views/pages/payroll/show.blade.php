@extends('layouts.app')

{{--
    One posted payroll run.

    «مبلغ ثبت‌شده» is what this run accrued and never changes. «مانده فعلی» is what
    the employee is owed TODAY — which is a different number the moment any of it has
    been paid, and showing only the first would tell the reader a salary is still
    outstanding when it has already been handed over.
--}}
@php
    use App\Domain\Accounting\Support\JalaliPeriod;
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="'حقوق دوره '.JalaliPeriod::label($run->jalali_period)"
    parentLabel="لیست‌های حقوق" :parentUrl="route('payroll.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    @if ($run->isReversed())
        <x-ui.alert variant="warning" title="این لیست حقوق برگشت خورده است"
            :message="'دلیل: '.$run->reversal_reason" />
    @endif

    <x-common.component-card :title="'حقوق دوره '.JalaliPeriod::label($run->jalali_period)">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi.compact label="جمع ناخالص" :value="$run->grossTotal()" type="toman" />
            <x-kpi.compact label="جمع خالص" :value="$run->netTotal()" type="toman" />
            <x-kpi.compact label="تعداد کارمند" :value="$run->items->count()" type="int" />
            <div>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                <div class="mt-1"><x-ui.status :status="$run->statusBadge()" :label="$run->statusLabel()" /></div>
            </div>
        </div>

        <dl class="mt-4 grid gap-3 text-theme-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">تاریخ ثبت</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                    <x-tables.ltr :value="$run->posted_at ? JalaliPeriod::fmtDateTime($run->posted_at) : '—'" :cell="false" />
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">ثبت‌کننده</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $run->creator?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">توضیح</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $run->notes ?? '—' }}</dd>
            </div>
        </dl>
    </x-common.component-card>

    <x-common.component-card title="کارکنان این لیست"
        desc="«مبلغ ثبت‌شده» تغییر نمی‌کند. «مانده فعلی» از دفتر خوانده می‌شود و با هر پرداخت کم می‌شود.">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="border-b border-gray-100 dark:border-gray-800">
                    <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3 font-medium">کارمند</th>
                        <th class="px-4 py-3 font-medium">حقوق ناخالص</th>
                        <th class="px-4 py-3 font-medium">کسر مساعده</th>
                        <th class="px-4 py-3 font-medium">خالص (ثبت‌شده)</th>
                        <th class="px-4 py-3 font-medium">مانده حقوق فعلی</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($run->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                {{ $item->employee?->party?->name ?? '—' }}
                            </td>
                            <x-tables.num :value="$item->gross" type="toman" tone="muted" />
                            <x-tables.num :value="$item->advances_deducted" type="toman" zero="—" tone="muted" />
                            <x-tables.num :value="$item->net" type="toman" />
                            <x-tables.num :value="(int) $balances[$item->id]" type="toman" />
                            <td class="px-4 py-3">
                                @if ($item->employee?->party)
                                    <a href="{{ route('employees.show', $item->employee->party) }}"
                                       class="text-theme-sm text-brand-500 hover:underline">حساب کارمند</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-common.component-card>

    @if ($run->journalEntry)
        <x-common.component-card title="سند حسابداری"
            :desc="'شماره سند: '.$run->journalEntry->uuid">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">حساب</th>
                            <th class="px-4 py-3 font-medium">شرح</th>
                            <th class="px-4 py-3 font-medium">بدهکار</th>
                            <th class="px-4 py-3 font-medium">بستانکار</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($run->journalEntry->lines as $line)
                            <tr>
                                <td class="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">
                                    {{ $line->account->code }} — {{ $line->account->name }}
                                </td>
                                <td class="px-4 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $line->memo ?? '—' }}</td>
                                <x-tables.num :value="(int) $line->debit" type="toman" zero="—" tone="muted" />
                                <x-tables.num :value="(int) $line->credit" type="toman" zero="—" tone="muted" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-common.component-card>
    @endif

    {{-- «سوابق پرداخت حقوق» tied to THIS run — the «پرداخت هم‌زمان» rows posted
         alongside the accrual above, plus any later standalone payment the
         operator chose to link back here. Each is its OWN entry (never merged
         with the accrual's), so it is reversed independently of the run. --}}
    @if ($run->payments->isNotEmpty())
        <x-common.component-card title="سوابق پرداخت حقوق"
            desc="هر پرداخت، سند حسابداری جداگانهٔ خودش را دارد و مستقل از این لیست برگشت می‌خورد.">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">کارمند</th>
                            <th class="px-4 py-3 font-medium">تاریخ پرداخت</th>
                            <th class="px-4 py-3 font-medium">مبلغ پرداخت</th>
                            <th class="px-4 py-3 font-medium">حساب پرداخت‌کننده</th>
                            <th class="px-4 py-3 font-medium">روش پرداخت</th>
                            <th class="px-4 py-3 font-medium">شماره پیگیری</th>
                            <th class="px-4 py-3 font-medium">وضعیت</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($run->payments as $payment)
                            <tr>
                                <td class="px-4 py-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    <a href="{{ route('employees.show', $payment->party) }}" class="hover:underline">
                                        {{ $payment->party->name }}
                                    </a>
                                </td>
                                <x-tables.ltr :value="JalaliPeriod::fmtDate($payment->accounting_date ?? $payment->paid_at)" />
                                <x-tables.num :value="(int) $payment->amount" type="toman" />
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $payment->bankAccount?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ \App\Domain\Receivables\Models\PartyPayment::METHODS[$payment->method] ?? ($payment->method ?? '—') }}</td>
                                <x-tables.ltr :value="$payment->reference" />
                                <td class="px-4 py-3">
                                    <x-ui.status :status="$payment->isReversed() ? 'cancelled' : 'completed'"
                                        :label="$payment->isReversed() ? 'برگشت‌خورده' : 'ثبت‌شده'" />
                                </td>
                                <td class="px-4 py-3">
                                    @include('pages.employees.partials.payment-reverse-control', ['payment' => $payment])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-common.component-card>
    @endif

    {{-- A posted run is immutable. The only correction is a reversal: the original
         entry stays exactly as posted and an opposing entry cancels it. --}}
    @if ($run->isPosted())
        <x-common.component-card title="برگشت لیست حقوق"
            desc="لیست ثبت‌شده ویرایش یا حذف نمی‌شود. با برگشت، سند اصلی دست‌نخورده می‌ماند و سند معکوس آن ثبت می‌گردد. اگر حقوقی از این لیست پرداخت شده باشد، ابتدا باید پرداخت‌ها برگشت بخورند.">
            <form method="POST" action="{{ route('payroll.reverse', $run) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-64">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">دلیل برگشت</label>
                    <input type="text" name="reason" required
                        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                </div>
                <button type="submit"
                    class="inline-flex h-10 items-center rounded-lg border border-error-300 px-4 text-theme-sm font-medium text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:text-error-400 dark:hover:bg-error-500/10">
                    برگشت لیست حقوق
                </button>
            </form>
        </x-common.component-card>
    @endif
</div>
@endsection
