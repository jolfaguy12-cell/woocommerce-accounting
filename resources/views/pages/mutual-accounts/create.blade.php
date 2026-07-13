@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت تهاتر جدید" parentLabel="حساب‌های دوطرفه" :parentUrl="route('mutual-accounts.index')" />

{{--
    The cap for every party/type pair is computed server-side and shipped with the
    form, so the user sees the ceiling BEFORE they type a number over it — and the
    service enforces the same ceiling again on submit, because a form is a
    convenience and the guard is the control.
--}}
<div class="mx-auto max-w-2xl space-y-4"
     x-data="{
        candidates: {{ Illuminate\Support\Js::from($candidates) }},
        party: '{{ old('party_id') }}',
        type: '{{ old('type') }}',
        amount: '{{ old('amount') }}',
        confirmed: false,
        get selected() { return this.candidates.find(c => String(c.id) === String(this.party)); },
        get cap() { return this.selected && this.type ? (this.selected.caps[this.type] ?? 0) : 0; },
        money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
        get overCap() { return this.cap > 0 && parseInt(this.amount || 0, 10) > this.cap; },
        get sentence() {
            if (!this.selected || !this.type || !this.amount || this.cap === 0 || this.overCap) return null;
            return `مبلغ ${this.money(this.amount)} تومان از دو مانده «${this.selected.name}» به‌صورت متقابل کسر می‌شود. هیچ وجهی جابه‌جا نمی‌شود و هیچ پرداختی انجام نمی‌گیرد؛ فقط دو مانده با هم تهاتر می‌شوند.`;
        },
     }">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    @if ($approvalThreshold !== null)
        <x-ui.alert variant="info" title="تأیید دو‌مرحله‌ای فعال است"
            message="تهاتر با مبلغ {{ number_format($approvalThreshold) }} تومان و بیشتر، تا تأیید کاربر دیگری در دفتر ثبت نمی‌شود." />
    @endif

    @if ($candidates->isEmpty())
        <x-states.state variant="empty"
            title="هیچ طرف حسابی مانده قابل تهاتر ندارد"
            message="تهاتر تنها زمانی ممکن است که یک طرف حساب، هم‌زمان دو مانده متقابل داشته باشد — مثلاً هم مشتری باشد و هم تأمین‌کننده." />
    @else
    <form method="POST" action="{{ route('mutual-accounts.store') }}" class="space-y-4">
        @csrf

        <x-common.component-card title="طرف حساب و ترکیب تهاتر">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">طرف حساب</label>
                    <select name="party_id" x-model="party" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($candidates as $c)
                            <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">ترکیب تهاتر</label>
                    <select name="type" x-model="type" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p x-show="selected && type" class="mt-1 text-xs" x-cloak
                       :class="cap > 0 ? 'text-gray-500 dark:text-gray-400' : 'text-error-500'">
                        <span x-show="cap > 0">حداکثر قابل تهاتر: <span x-text="money(cap)"></span> تومان</span>
                        <span x-show="cap === 0">این طرف حساب در این ترکیب مانده‌ای برای تهاتر ندارد.</span>
                    </p>
                </div>

                <div>
                    <label class="{{ $labelClass }}">مبلغ (تومان)</label>
                    <input type="number" name="amount" min="1" x-model="amount" dir="ltr" class="{{ $inputClass }}">
                    <p x-show="overCap" x-cloak class="mt-1 text-xs text-error-500">
                        بیشتر از مانده قابل تهاتر است. تهاتر بیش از مانده، بدهی را تسویه نمی‌کند بلکه بدهی معکوس می‌سازد.
                    </p>
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ</label>
                    <input type="date" name="offset_date" value="{{ old('offset_date', $today) }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">دلیل تهاتر (الزامی)</label>
                    <input type="text" name="reason" value="{{ old('reason') }}" maxlength="255" class="{{ $inputClass }}"
                           placeholder="مثلاً: توافق تهاتر فاکتور خرید با مطالبات فروش">
                </div>

                <div>
                    <label class="{{ $labelClass }}">کد پیگیری (اختیاری)</label>
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
                        متن بالا را خواندم و ثبت این تهاتر را تأیید می‌کنم.
                    </label>
                </div>
            </template>
            <template x-if="!sentence">
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ تهاتر، طرف حساب، ترکیب و مبلغ معتبر را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت تهاتر
            </button>
        </x-common.component-card>
    </form>
    @endif
</div>
@endsection
