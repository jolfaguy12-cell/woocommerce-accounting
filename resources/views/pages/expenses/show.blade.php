@extends('layouts.app')

{{--
    One expense, and the settlements posted against it.

    «تسویه هزینه پرداخت‌نشده» debits accounts payable and credits the bank. It creates
    NO second expense: the cost was recognised the day this expense was entered, and
    recognising it again on the day it is paid would double it — while balancing
    perfectly, which is why that mistake survives review.

    Partial settlement is a normal case, not an edge one, and the cap is always the
    REMAINING balance — which is also why a double submit is harmless: the second one
    finds nothing left to pay.
--}}
@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$expense->description" parentLabel="هزینه‌ها" :parentUrl="route('expenses.index')" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    <x-common.component-card :title="$expense->description">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi.compact label="مبلغ هزینه" :value="(int) $expense->amount" type="toman" />
            <x-kpi.compact label="پرداخت‌شده" :value="$settled" type="toman" />
            <x-kpi.compact label="مانده" :value="$remaining" type="toman" />
            <div>
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">وضعیت تسویه</p>
                <div class="mt-1"><x-ui.status :status="$status->badge()" :label="$status->label()" /></div>
            </div>
        </div>

        <dl class="mt-4 grid gap-3 text-theme-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">تاریخ</dt>
                <dd class="mt-0.5"><x-tables.ltr :value="$date_fa" :cell="false" /></dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">دسته</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $expense->category?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">منبع پرداخت</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $expense->fundingSource()->label() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">بستانکار / پرداخت‌کننده</dt>
                <dd class="mt-0.5 text-gray-800 dark:text-white/90">
                    @if ($expense->fundedByParty)
                        <a href="{{ route('parties.show', $expense->fundedByParty) }}" class="text-brand-500 hover:underline">
                            {{ $expense->fundedByParty->name }}
                        </a>
                    @else
                        {{ $expense->bankAccount?->name ?? '—' }}
                    @endif
                </dd>
            </div>
        </dl>
    </x-common.component-card>

    @if ($settleable && $remaining > 0)
        <x-common.component-card title="تسویه هزینه پرداخت‌نشده"
            :desc="'حداکثر قابل پرداخت: '.number_format($remaining).' تومان. پرداخت جزئی مجاز است؛ هزینه تا تسویه کامل «بخشی پرداخت‌شده» می‌ماند.'">
            <form method="POST" action="{{ route('expenses.settle', $expense) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-form.money-input name="amount" label="مبلغ پرداخت" :value="$remaining" required />
                <div>
                    <label class="{{ $labelClass }}">از حساب</label>
                    <select name="bank_account_id" required class="{{ $selectClass }}">
                        @foreach ($banks as $bank)
                            <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-form.jalali-date name="accounting_date" label="تاریخ سند" :value="$today" required />
                <div>
                    <label class="{{ $labelClass }}">مرجع / شماره پیگیری</label>
                    <input type="text" name="reference" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">یادداشت</label>
                    <input type="text" name="note" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <button type="submit"
                        class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                        تسویه هزینه پرداخت‌نشده
                    </button>
                    <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                        این عملیات هیچ هزینه جدیدی ثبت نمی‌کند: بدهی از حساب‌های پرداختنی (۲۰۰۰) کم و از حساب بانکی برداشت می‌شود.
                    </p>
                </div>
            </form>
        </x-common.component-card>
    @elseif (! $settleable)
        <x-ui.alert variant="info" title="این هزینه از حساب شرکت پرداخت شده است"
            message="فقط هزینه‌ای که به‌صورت «پرداخت‌نشده» ثبت شده باشد، تسویه می‌شود. هزینه‌ای که کارمند یا شریک پرداخت کرده باشد، از طریق «بازپرداخت هزینه» به او برگردانده می‌شود." />
    @endif

    @if ($settlements->isNotEmpty())
        <x-common.component-card title="پرداخت‌های این هزینه">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">تاریخ</th>
                            <th class="px-4 py-3 font-medium">مبلغ</th>
                            <th class="px-4 py-3 font-medium">از حساب</th>
                            <th class="px-4 py-3 font-medium">مرجع</th>
                            <th class="px-4 py-3 font-medium">ثبت‌کننده</th>
                            <th class="px-4 py-3 font-medium">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($settlements as $payment)
                            <tr>
                                <x-tables.ltr :value="JalaliPeriod::fmtDate($payment->accounting_date ?? $payment->paid_at)" />
                                <x-tables.num :value="(int) $payment->amount" type="toman" />
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $payment->bankAccount?->name ?? '—' }}</td>
                                <x-tables.ltr :value="$payment->reference" />
                                <td class="px-4 py-3 text-theme-sm text-gray-600 dark:text-gray-400">{{ $payment->creator?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.status :status="$payment->isReversed() ? 'cancelled' : 'completed'"
                                        :label="$payment->isReversed() ? 'برگشت‌خورده' : 'ثبت‌شده'" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-common.component-card>
    @endif
</div>
@endsection
