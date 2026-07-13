@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت وام جدید" parentLabel="وام و اقساط" :parentUrl="route('loans.index')" />

{{--
    The direction is the whole decision on this page, and its two names point the
    opposite way from their accounts: «وام دریافتی» means the money ARRIVED (and we
    now owe it), «وام پرداختی» means the money LEFT (and it is owed to us). The
    sentence at the bottom spells that out in plain Persian before anything is saved.
--}}
<div class="mx-auto max-w-2xl space-y-4"
     x-data="{
        methods: {{ Illuminate\Support\Js::from($methods) }},
        direction: '{{ old('direction') }}',
        method: '{{ old('interest_method', 'none') }}',
        principal: '{{ old('principal') }}',
        party: '{{ old('party_id') }}',
        count: '{{ old('installment_count') }}',
        confirmed: false,
        get selectedMethod() { return this.methods.find(m => m.value === this.method); },
        get needsRate() { return this.selectedMethod ? this.selectedMethod.needs_rate : false; },
        get needsAmount() { return this.selectedMethod ? this.selectedMethod.needs_amount : false; },
        money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
        get sentence() {
            if (!this.direction || !this.principal || !this.party) return null;
            const amount = this.money(this.principal);
            const parts = this.count && parseInt(this.count, 10) > 0
                ? ` بازپرداخت در ${this.money(this.count)} قسط برنامه‌ریزی می‌شود.`
                : ' برنامهٔ اقساط ثبت نمی‌شود و وام یکجا تسویه خواهد شد.';

            return this.direction === 'payable'
                ? `مبلغ ${amount} تومان به حساب بانکی انتخاب‌شده وارد می‌شود و به همان اندازه به این طرف حساب بدهکار می‌شویم.${parts}`
                : `مبلغ ${amount} تومان از حساب بانکی انتخاب‌شده خارج می‌شود و به همان اندازه از این طرف حساب طلبکار می‌شویم.${parts}`;
        },
     }">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    @if ($approvalThreshold !== null)
        <x-ui.alert variant="info" title="تأیید دو‌مرحله‌ای فعال است"
            message="وام با مبلغ {{ number_format($approvalThreshold) }} تومان و بیشتر، تا تأیید کاربر دیگری در دفتر ثبت نمی‌شود." />
    @endif

    <form method="POST" action="{{ route('loans.store') }}" class="space-y-4">
        @csrf

        <x-common.component-card title="مشخصات وام">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">طرف حساب</label>
                    <select name="party_id" x-model="party" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($parties as $p)
                            <option value="{{ $p->id }}" @selected(old('party_id') == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">نوع وام</label>
                    <select name="direction" x-model="direction" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($directions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">اصل وام (تومان)</label>
                    <input type="number" name="principal" min="1" x-model="principal" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">حساب بانکی</label>
                    <select name="bank_account_id" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($bankAccounts as $b)
                            <option value="{{ $b->id }}" @selected(old('bank_account_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ دریافت / پرداخت</label>
                    <input type="date" name="received_at" value="{{ old('received_at', $today) }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ سررسید نهایی (اختیاری)</label>
                    <input type="date" name="maturity_date" value="{{ old('maturity_date') }}" dir="ltr" class="{{ $inputClass }}">
                </div>
            </div>
        </x-common.component-card>

        <x-common.component-card title="سود و برنامه اقساط">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">روش محاسبه سود</label>
                    <select name="interest_method" x-model="method" class="{{ $selectClass }}">
                        @foreach ($methods as $m)
                            <option value="{{ $m['value'] }}">{{ $m['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">تعداد اقساط</label>
                    <input type="number" name="installment_count" min="0" max="600" x-model="count" dir="ltr" class="{{ $inputClass }}">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">اگر خالی یا صفر باشد، برنامهٔ اقساط ساخته نمی‌شود.</p>
                </div>

                <div x-show="needsRate" x-cloak>
                    <label class="{{ $labelClass }}">نرخ سود سالانه (٪)</label>
                    <input type="number" step="0.001" name="interest_rate" value="{{ old('interest_rate') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div x-show="needsAmount" x-cloak>
                    <label class="{{ $labelClass }}">مبلغ کل سود (تومان)</label>
                    <input type="number" name="interest_amount" min="0" value="{{ old('interest_amount') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">کد پیگیری / شماره قرارداد (اختیاری)</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" dir="ltr" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">یادداشت (اختیاری)</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" class="{{ $inputClass }}">
                </div>
            </div>
        </x-common.component-card>

        <x-common.component-card title="تأیید نهایی">
            <template x-if="sentence">
                <div class="space-y-3">
                    <p class="rounded-lg bg-warning-50 p-3 text-sm leading-6 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                       x-text="sentence"></p>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" x-model="confirmed" class="h-4 w-4 rounded border-gray-300 text-brand-500">
                        متن بالا را خواندم و ثبت این وام را تأیید می‌کنم.
                    </label>
                </div>
            </template>
            <template x-if="!sentence">
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ وام، طرف حساب، نوع و مبلغ را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت وام
            </button>
        </x-common.component-card>
    </form>
</div>
@endsection
