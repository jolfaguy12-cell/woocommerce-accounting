@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت وام جدید" parentLabel="وام و اقساط" :parentUrl="route('loans.index')" />

{{--
    Everything a loan actually needs is in the first card, and nothing else is.

    Interest, fees, penalties and an installment schedule are all optional and all
    default to zero, so the common case — «فلانی ۵۰ میلیون قرض داد» — is six fields
    and done. They live behind «تنظیمات پیشرفته» because a form that asks for an
    interest method before it will accept a simple loan teaches people that the
    system is not worth the trouble, and the loan gets written on paper instead.

    The two direction names point the opposite way from their accounts: «وام دریافتی»
    means the money ARRIVED (we owe it), «وام پرداختی» means it LEFT (it is owed to
    us). The sentence at the bottom says so in plain Persian before anything is saved.
--}}
<div class="mx-auto max-w-2xl space-y-4"
     x-data="{
        methods: {{ Illuminate\Support\Js::from($methods) }},
        direction: '{{ old('direction') }}',
        method: '{{ old('interest_method', 'none') }}',
        principal: '{{ old('principal') }}',
        party: '{{ old('party_id') }}',
        count: '{{ old('installment_count') }}',
        advanced: {{ old('installment_count') || old('interest_method', 'none') !== 'none' ? 'true' : 'false' }},
        confirmed: false,
        get selectedMethod() { return this.methods.find(m => m.value === this.method); },
        get needsRate() { return this.selectedMethod ? this.selectedMethod.needs_rate : false; },
        get needsAmount() { return this.selectedMethod ? this.selectedMethod.needs_amount : false; },
        money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
        get sentence() {
            if (!this.direction || !this.principal || !this.party) return null;
            const amount = this.money(this.principal);
            const schedule = this.count && parseInt(this.count, 10) > 0
                ? ` بازپرداخت در ${this.money(this.count)} قسط برنامه‌ریزی می‌شود.`
                : '';

            return this.direction === 'payable'
                ? `مبلغ ${amount} تومان به حساب انتخاب‌شده وارد می‌شود و به همان اندازه به این طرف حساب بدهکار می‌شویم.${schedule}`
                : `مبلغ ${amount} تومان از حساب انتخاب‌شده خارج می‌شود و به همان اندازه از این طرف حساب طلبکار می‌شویم.${schedule}`;
        },
     }">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    @if ($approvalThreshold !== null)
        <x-ui.alert variant="info" title="تأیید مرحله‌ای فعال است"
            message="وام با مبلغ {{ number_format($approvalThreshold) }} تومان و بیشتر، تا تأیید در دفتر ثبت نمی‌شود." />
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
                    <label class="{{ $labelClass }}">مبلغ اصل وام (تومان)</label>
                    <input type="number" name="principal" min="1" x-model="principal" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">حساب بانکی یا صندوق</label>
                    <select name="bank_account_id" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($bankAccounts as $b)
                            <option value="{{ $b->id }}" @selected(old('bank_account_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ دریافت یا پرداخت</label>
                    <input type="date" name="received_at" value="{{ old('received_at', $today) }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ سررسید</label>
                    <input type="date" name="maturity_date" value="{{ old('maturity_date') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">توضیحات</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" maxlength="1000" class="{{ $inputClass }}">
                </div>
            </div>
        </x-common.component-card>

        {{-- Optional, and genuinely optional: everything in here defaults to zero. --}}
        <x-common.component-card title="تنظیمات پیشرفته (اختیاری)">
            <button type="button" @click="advanced = !advanced"
                class="flex w-full items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                <span>سود، اقساط و شماره قرارداد</span>
                <span x-text="advanced ? 'بستن −' : 'باز کردن +'" class="text-brand-500"></span>
            </button>

            <div x-show="advanced" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <p class="rounded-lg bg-gray-50 p-3 text-xs leading-6 text-gray-600 dark:bg-white/5 dark:text-gray-400">
                        اگر وام بدون سود است، این بخش را دست نزنید. سود، کارمزد و جریمه دیرکرد همگی پیش‌فرض صفر هستند
                        و برای ثبت وام لازم نیستند.
                    </p>
                </div>

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
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">خالی یا صفر: بدون برنامهٔ اقساط، تسویه یکجا.</p>
                </div>

                <div x-show="needsRate" x-cloak>
                    <label class="{{ $labelClass }}">نرخ سود سالانه (٪)</label>
                    <input type="number" step="0.001" name="interest_rate" value="{{ old('interest_rate') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div x-show="needsAmount" x-cloak>
                    <label class="{{ $labelClass }}">مبلغ کل سود (تومان)</label>
                    <input type="number" name="interest_amount" min="0" value="{{ old('interest_amount') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">شماره قرارداد / کد پیگیری</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" dir="ltr" class="{{ $inputClass }}">
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
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ وام، طرف حساب، نوع و مبلغ اصل وام را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت وام
            </button>
        </x-common.component-card>
    </form>
</div>
@endsection
