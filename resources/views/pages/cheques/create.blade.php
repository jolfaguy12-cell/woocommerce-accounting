@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت چک جدید" parentLabel="چک‌ها" :parentUrl="route('cheques.index')" />

{{--
    Registering a cheque does NOT record a payment — that is the thing to be clear
    about before saving. It moves the balance out of the receivable/payable and into
    the cheque account, where it waits. The money only arrives when the cheque clears.
--}}
<div class="mx-auto max-w-2xl space-y-4"
     x-data="{
        direction: '{{ old('direction') }}',
        amount: '{{ old('amount') }}',
        party: '{{ old('party_id') }}',
        confirmed: false,
        money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
        get sentence() {
            if (!this.direction || !this.amount || !this.party) return null;
            const amount = this.money(this.amount);

            return this.direction === 'receivable'
                ? `چک ${amount} تومانی به‌عنوان «اسناد دریافتنی» ثبت می‌شود و به همان اندازه از طلب ما از این مشتری کم می‌شود. توجه: این پرداخت نیست؛ وجه چک تنها در زمان وصول به حساب می‌نشیند.`
                : `چک ${amount} تومانی به‌عنوان «اسناد پرداختنی» ثبت می‌شود و به همان اندازه از بدهی ما به این طرف حساب کم می‌شود. توجه: وجه آن تنها در زمان پاس شدن چک از حساب خارج می‌شود.`;
        },
     }"
     x-on:party-selected="party = $event.detail.id ?? ''"
     x-on:money-input="if ($event.detail.name === 'amount') amount = $event.detail.value">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    <form method="POST" action="{{ route('cheques.store') }}" class="space-y-4">
        @csrf

        <x-common.component-card title="مشخصات چک">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.party-select name="party_id" label="طرف حساب"
                    :value="old('party_id')" :selected-name="$selectedPartyName" required />

                <div>
                    <label class="{{ $labelClass }}">نوع چک</label>
                    <select name="direction" x-model="direction" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($directions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">مبلغ (تومان)</label>
                    <x-form.money-input name="amount" :label="null" :value="old('amount')" required />
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ سررسید</label>
                    <x-form.jalali-date name="due_date" :label="null" :value="old('due_date', $today)" required />
                </div>

                <div>
                    <label class="{{ $labelClass }}">شماره چک</label>
                    <input type="text" name="serial" value="{{ old('serial') }}" dir="ltr" maxlength="60" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">نام بانک</label>
                    <input type="text" name="bank_name" value="{{ old('bank_name') }}" maxlength="120" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">کد پیگیری (اختیاری)</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">شرح (اختیاری)</label>
                    <input type="text" name="description" value="{{ old('description') }}" maxlength="255" class="{{ $inputClass }}">
                </div>

                <div class="sm:col-span-2">
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
                        متن بالا را خواندم و ثبت این چک را تأیید می‌کنم.
                    </label>
                </div>
            </template>
            <template x-if="!sentence">
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ چک، طرف حساب، نوع و مبلغ را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت چک
            </button>
        </x-common.component-card>
    </form>
</div>
@endsection
