@extends('layouts.app')

@php
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $checkClass = 'h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900';
    $submitClass = 'h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="فرم‌های سریع" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif

    @if ($errors->any())
        <x-ui.alert variant="error" title="خطا در ثبت" :message="$errors->first()" />
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">

        {{-- ---------- Expense ---------- --}}
        <x-common.component-card title="ثبت هزینه">
            <form method="POST" action="{{ route('fast-forms.expense') }}" class="space-y-3">
                @csrf
                <select name="expense_category_id" required class="{{ $selectClass }}">
                    <option value="">دسته هزینه…</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('expense_category_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>

                <select name="cost_center_id" class="{{ $selectClass }}">
                    <option value="">مرکز هزینه (اختیاری)</option>
                    @foreach ($cost_centers as $cc)
                        <option value="{{ $cc->id }}" @selected(old('cost_center_id') == $cc->id)>{{ $cc->name }}</option>
                    @endforeach
                </select>

                <select name="bank_account_id" required class="{{ $selectClass }}">
                    <option value="">پرداخت از…</option>
                    @foreach ($banks as $b)
                        <option value="{{ $b->id }}" @selected(old('bank_account_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>

                <input type="number" name="amount" min="1" required dir="ltr" placeholder="مبلغ (تومان)"
                    value="{{ old('amount') }}" class="{{ $inputClass }}">

                <input type="text" name="description" required maxlength="255" placeholder="شرح"
                    value="{{ old('description') }}" class="{{ $inputClass }}">

                {{-- Unchecked checkboxes aren't submitted; the hidden field keeps the
                     boolean explicit so "off" reaches the validator as 0, not missing. --}}
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="affects_partner_profit" value="0">
                    <input type="checkbox" name="affects_partner_profit" value="1" @checked(old('affects_partner_profit', true)) class="{{ $checkClass }}">
                    مؤثر بر سود شرکا
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="is_capital" value="0">
                    <input type="checkbox" name="is_capital" value="1" @checked(old('is_capital', false)) class="{{ $checkClass }}">
                    سرمایه‌ای (دارایی ثابت)
                </label>

                <button type="submit" class="{{ $submitClass }}">ثبت هزینه</button>
            </form>
        </x-common.component-card>

        {{-- ---------- Channel top-up ---------- --}}
        <x-common.component-card title="شارژ / هزینه کانال">
            <form method="POST" action="{{ route('fast-forms.topup') }}" class="space-y-3">
                @csrf
                <select name="channel_id" required class="{{ $selectClass }}">
                    <option value="">کانال…</option>
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                    @endforeach
                </select>

                <select name="bank_account_id" required class="{{ $selectClass }}">
                    <option value="">پرداخت از…</option>
                    @foreach ($banks as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>

                <input type="number" name="amount" min="1" required dir="ltr" placeholder="مبلغ شارژ (تومان)" class="{{ $inputClass }}">
                <input type="text" name="note" maxlength="255" placeholder="توضیح (اختیاری)" class="{{ $inputClass }}">

                <button type="submit" class="{{ $submitClass }}" @disabled($channels->isEmpty())>ثبت شارژ / هزینه کانال</button>
                @if ($channels->isEmpty())
                    <p class="text-xs text-gray-400">کانالی با مدل هزینه شارژ/دوره‌ای فعال نیست.</p>
                @endif
            </form>
        </x-common.component-card>

        {{-- ---------- Customer payment ---------- --}}
        <x-common.component-card title="دریافت از مشتری">
            {{-- Alpine only narrows the credit-order list to the picked customer (cosmetic);
                 the server still validates party_id/credit_order_id independently. --}}
            <form method="POST" action="{{ route('fast-forms.payment') }}" class="space-y-3"
                x-data="{ partyId: '', credits: @js($open_credits) }">
                @csrf
                <select name="party_id" required x-model="partyId" class="{{ $selectClass }}">
                    <option value="">مشتری…</option>
                    @foreach ($customers as $cu)
                        <option value="{{ $cu->id }}">{{ $cu->name }}</option>
                    @endforeach
                </select>

                <template x-if="credits.filter(c => String(c.party_id) === partyId).length > 0">
                    <select name="credit_order_id" class="{{ $selectClass }}">
                        <option value="">بدون اتصال به فروش اعتباری</option>
                        <template x-for="c in credits.filter(c => String(c.party_id) === partyId)" :key="c.id">
                            <option :value="c.id" x-text="(c.description ?? 'اعتباری') + ' — مانده ' + Number(c.remaining).toLocaleString('fa-IR')"></option>
                        </template>
                    </select>
                </template>

                <select name="bank_account_id" required class="{{ $selectClass }}">
                    <option value="">واریز به…</option>
                    @foreach ($banks as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>

                <input type="number" name="amount" min="1" required dir="ltr" placeholder="مبلغ (تومان)" class="{{ $inputClass }}">

                <button type="submit" class="{{ $submitClass }}" @disabled($customers->isEmpty())>ثبت دریافت</button>
            </form>
        </x-common.component-card>

        {{-- ---------- New bank account ---------- --}}
        <x-common.component-card title="حساب بانکی / صندوق جدید">
            <form method="POST" action="{{ route('fast-forms.bank') }}" class="space-y-3">
                @csrf
                <input type="text" name="name" required maxlength="100" placeholder="نام حساب (مثل: بانک ملت اصلی)" class="{{ $inputClass }}">
                <input type="text" name="bank_name" maxlength="100" placeholder="نام بانک" class="{{ $inputClass }}">
                <input type="text" name="iban" maxlength="34" dir="ltr" placeholder="شبا (اختیاری)" class="{{ $inputClass }}">

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="is_cash" value="0">
                    <input type="checkbox" name="is_cash" value="1" class="{{ $checkClass }}">
                    صندوق نقدی است
                </label>

                <button type="submit"
                    class="h-10 w-full rounded-lg border border-gray-300 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                    ساخت حساب
                </button>
            </form>
        </x-common.component-card>

    </div>
</div>
@endsection
