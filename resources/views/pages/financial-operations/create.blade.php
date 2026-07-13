@extends('layouts.app')

@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="ثبت عملیات مالی جدید" parentLabel="عملیات مالی" :parentUrl="route('financial-operations.index')" />

{{--
    One form, three operations. Alpine only shows/hides the fields of the selected
    operation — the submitted data is validated and posted server-side, and the
    confirmation sentence below is built from what the user actually chose, in
    plain Persian, so nobody posts a journal entry they did not mean to.
--}}
<div class="mx-auto max-w-3xl space-y-4"
     x-data="{
        type: '{{ old('type', $selectedType) }}',
        accounts: {{ Illuminate\Support\Js::from($bankAccounts) }},
        from: '{{ old('from_bank_account_id') }}',
        to: '{{ old('to_bank_account_id') }}',
        bank: '{{ old('bank_account_id') }}',
        counter: '{{ old('counter_account_id') }}',
        amount: '{{ old('amount', '') }}',
        fee: '{{ old('bank_fee', 0) }}',
        confirmed: false,
        name(id) { const a = this.accounts.find(a => String(a.id) === String(id)); return a ? a.name : '…'; },
        money(v) { const n = parseInt(v || 0, 10); return isNaN(n) ? '۰' : n.toLocaleString('fa-IR'); },
        get total() { return (parseInt(this.amount || 0, 10) || 0) + (this.type === 'transfer' ? (parseInt(this.fee || 0, 10) || 0) : 0); },
        get sentence() {
            if (!this.amount) return null;
            if (this.type === 'transfer') {
                if (!this.from || !this.to) return null;
                let s = `مبلغ ${this.money(this.amount)} تومان از حساب «${this.name(this.from)}» به حساب «${this.name(this.to)}» منتقل می‌شود.`;
                if (parseInt(this.fee || 0, 10) > 0) {
                    s += ` همچنین ${this.money(this.fee)} تومان کارمزد بانکی از حساب مبدأ کسر و به‌عنوان هزینه ثبت می‌شود. مجموع کسر از حساب مبدأ: ${this.money(this.total)} تومان.`;
                }
                return s + ' این عملیات درآمد یا هزینه‌ای ایجاد نمی‌کند؛ فقط وجه بین حساب‌های خودمان جابه‌جا می‌شود.';
            }
            if (!this.bank) return null;
            return this.type === 'deposit'
                ? `مبلغ ${this.money(this.amount)} تومان به حساب «${this.name(this.bank)}» واریز و طرف مقابل آن در حساب انتخاب‌شده ثبت می‌شود.`
                : `مبلغ ${this.money(this.amount)} تومان از حساب «${this.name(this.bank)}» برداشت و طرف مقابل آن در حساب انتخاب‌شده ثبت می‌شود.`;
        },
     }">

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    @if ($approvalThreshold !== null)
        <x-ui.alert variant="info" title="تأیید دو‌مرحله‌ای فعال است"
            message="عملیات با مبلغ {{ number_format($approvalThreshold) }} تومان و بیشتر، تا زمانی که کاربر دیگری آن را تأیید نکند در دفتر ثبت نمی‌شود." />
    @endif

    <form method="POST" action="{{ route('financial-operations.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="type" :value="type">

        <x-common.component-card title="نوع عملیات">
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($types as $key => $label)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm transition"
                           :class="type === '{{ $key }}'
                               ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400'
                               : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5'">
                        <input type="radio" x-model="type" value="{{ $key }}" class="h-4 w-4 text-brand-500">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </x-common.component-card>

        {{-- ---------------- Transfer ---------------- --}}
        <template x-if="type === 'transfer'">
            <x-common.component-card title="انتقال بین حساب‌ها">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}">از حساب (مبدأ)</label>
                        <select name="from_bank_account_id" x-model="from" class="{{ $selectClass }}">
                            <option value="">انتخاب کنید…</option>
                            @foreach ($bankAccounts as $b)
                                <option value="{{ $b['id'] }}">{{ $b['name'] }} — موجودی {{ number_format($b['balance']) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">به حساب (مقصد)</label>
                        <select name="to_bank_account_id" x-model="to" class="{{ $selectClass }}">
                            <option value="">انتخاب کنید…</option>
                            @foreach ($bankAccounts as $b)
                                <option value="{{ $b['id'] }}">{{ $b['name'] }} — موجودی {{ number_format($b['balance']) }}</option>
                            @endforeach
                        </select>
                        <p x-show="from && from === to" class="mt-1 text-xs text-error-500">مبدأ و مقصد نمی‌توانند یک حساب باشند.</p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">مبلغ (تومان)</label>
                        <input type="number" name="amount" min="1" x-model="amount" dir="ltr" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">کارمزد بانکی (تومان)</label>
                        <input type="number" name="bank_fee" min="0" x-model="fee" dir="ltr" class="{{ $inputClass }}">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">از حساب مبدأ کسر و در «کارمزد بانکی» ثبت می‌شود.</p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">تاریخ</label>
                        <input type="date" name="transfer_date" value="{{ old('transfer_date', $today) }}" dir="ltr" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">روش</label>
                        <select name="method" class="{{ $selectClass }}">
                            @foreach ($methods as $key => $label)
                                <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
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
        </template>

        {{-- ------------ Deposit / withdrawal ------------ --}}
        <template x-if="type === 'deposit' || type === 'withdrawal'">
            <x-common.component-card title="واریز / برداشت مستقیم">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}"><span x-text="type === 'deposit' ? 'واریز به حساب' : 'برداشت از حساب'"></span></label>
                        <select name="bank_account_id" x-model="bank" class="{{ $selectClass }}">
                            <option value="">انتخاب کنید…</option>
                            @foreach ($bankAccounts as $b)
                                <option value="{{ $b['id'] }}">{{ $b['name'] }} — موجودی {{ number_format($b['balance']) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">حساب مقابل (اجباری)</label>
                        <select name="counter_account_id" x-model="counter" class="{{ $selectClass }}">
                            <option value="">انتخاب کنید…</option>
                            @foreach ($counterAccounts as $a)
                                <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>
                            @endforeach
                        </select>
                        {{-- The whole point of the mandatory counter-account, said plainly. --}}
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            پول از هیچ‌جا نمی‌آید و به هیچ‌جا نمی‌رود: باید مشخص کنید طرف دیگر این تراکنش کدام حساب است.
                        </p>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">بابت</label>
                        <select name="purpose" class="{{ $selectClass }}">
                            @foreach ($purposes as $key => $label)
                                <option value="{{ $key }}" @selected(old('purpose') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">طرف حساب (اختیاری)</label>
                        <select name="party_id" class="{{ $selectClass }}">
                            <option value="">—</option>
                            @foreach ($parties as $p)
                                <option value="{{ $p->id }}" @selected(old('party_id') == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">مبلغ (تومان)</label>
                        <input type="number" name="amount" min="1" x-model="amount" dir="ltr" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">تاریخ</label>
                        <input type="date" name="transaction_date" value="{{ old('transaction_date', $today) }}" dir="ltr" class="{{ $inputClass }}">
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
        </template>

        {{-- ---------------- Confirmation ---------------- --}}
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
                <p class="text-sm text-gray-500 dark:text-gray-400">برای دیدن خلاصهٔ عملیات، حساب‌ها و مبلغ را کامل کنید.</p>
            </template>

            <button type="submit" :disabled="!confirmed || !sentence"
                class="mt-4 h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                ثبت عملیات
            </button>
        </x-common.component-card>
    </form>
</div>
@endsection
