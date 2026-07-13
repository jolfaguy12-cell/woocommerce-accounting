@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت عملیات شریک" parentLabel="عملیات شرکا" :parentUrl="route('partner-operations.index')" />

{{--
    Each operation type asks for exactly what it needs and nothing else: a profit
    distribution moves no cash so it shows no bank account; a reimbursement needs
    to know WHICH expense the partner covered. The type also decides the accounts —
    that lives in PartnerOperationType, never here.
--}}
<div class="mx-auto max-w-2xl space-y-4"
     x-data="{
        types: {{ Illuminate\Support\Js::from($types) }},
        type: '{{ old('type') }}',
        party: '{{ old('party_id') }}',
        amount: '{{ old('amount') }}',
        confirmed: false,
        get selectedType() { return this.types.find(t => t.value === this.type); },
        get movesCash() { return this.selectedType ? this.selectedType.moves_cash : false; },
        get needsCounter() { return this.selectedType ? this.selectedType.needs_counter : false; },
        money(v) { return (parseInt(v || 0, 10) || 0).toLocaleString('fa-IR'); },
        get sentence() {
            if (!this.selectedType || !this.party || !this.amount) return null;
            const label = this.selectedType.label;
            const amount = this.money(this.amount);
            return this.movesCash
                ? `«${label}» به مبلغ ${amount} تومان ثبت می‌شود و وجه آن از/به حساب بانکی انتخاب‌شده جابه‌جا می‌گردد.`
                : `«${label}» به مبلغ ${amount} تومان ثبت می‌شود. در این عملیات هیچ وجهی جابه‌جا نمی‌شود؛ فقط مانده‌های حسابداری شریک تغییر می‌کند.`;
        },
     }">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    @if ($approvalThreshold !== null)
        <x-ui.alert variant="info" title="تأیید دو‌مرحله‌ای فعال است"
            message="عملیات با مبلغ {{ number_format($approvalThreshold) }} تومان و بیشتر، تا تأیید کاربر دیگری در دفتر ثبت نمی‌شود." />
    @endif

    @if ($partners->isEmpty())
        <x-states.state variant="empty"
            title="هیچ طرف حسابی نقش شریک ندارد"
            message="ابتدا از صفحهٔ طرف حساب‌ها، نقش «شریک» را برای فرد موردنظر فعال کنید." />
    @else
    <form method="POST" action="{{ route('partner-operations.store') }}" class="space-y-4">
        @csrf

        <x-common.component-card title="شریک و نوع عملیات">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">شریک</label>
                    <select name="party_id" x-model="party" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($partners as $p)
                            <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">نوع عملیات</label>
                    <select name="type" x-model="type" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($types as $t)
                            <option value="{{ $t['value'] }}">{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">مبلغ (تومان)</label>
                    <input type="number" name="amount" min="1" x-model="amount" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">تاریخ</label>
                    <input type="date" name="operation_date" value="{{ old('operation_date', $today) }}" dir="ltr" class="{{ $inputClass }}">
                </div>

                <div x-show="movesCash" x-cloak>
                    <label class="{{ $labelClass }}">حساب بانکی</label>
                    <select name="bank_account_id" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($bankAccounts as $b)
                            <option value="{{ $b->id }}" @selected(old('bank_account_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="needsCounter" x-cloak>
                    <label class="{{ $labelClass }}">کدام هزینه را پرداخت کرده است؟</label>
                    <select name="counter_account_id" class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($expenseAccounts as $a)
                            <option value="{{ $a->id }}" @selected(old('counter_account_id') == $a->id)>{{ $a->code }} — {{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">شرح</label>
                    <input type="text" name="description" value="{{ old('description') }}" maxlength="255" class="{{ $inputClass }}">
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
                        متن بالا را خواندم و ثبت این عملیات را تأیید می‌کنم.
                    </label>
                </div>
            </template>
            <template x-if="!sentence">
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ عملیات، شریک، نوع و مبلغ را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت عملیات
            </button>
        </x-common.component-card>
    </form>
    @endif
</div>
@endsection
