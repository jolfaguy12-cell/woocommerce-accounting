@extends('layouts.app')

{{--
    «بازپرداخت هزینه کارمند» / «بازپرداخت هزینه شریک» — one form, one operation.

    The expense already booked the debt when it was recorded: an employee who paid
    from their own pocket was credited to 2350, a partner to 2600, and NEITHER touched
    a company bank account — because no company money moved. This is the day we hand
    the money back: it debits that same account and credits the bank it really left.

    The ceiling is the person's OUTSTANDING BALANCE on that account, read from the
    ledger — not the amount of the expense picked below. Someone may have funded a
    dozen small expenses and be paid back once, and linking to one of them is
    optional context, not the cap.
--}}
@php
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="بازپرداخت هزینه" parentLabel="هزینه‌ها" :parentUrl="route('expenses.index')" />

@php
    // The type decides which ROLE the party picker filters to, and the picker resolves
    // that server-side (it is a Blade component, not an Alpine one). So changing the
    // type reloads the page — a full GET round-trip, exactly like every other filter
    // in this app. Alpine holds only the label on the button.
    $current = collect($types)->firstWhere('value', $type->value);
@endphp

<div class="mx-auto max-w-2xl space-y-4">

    @foreach ($errors->all() as $error)
        <x-ui.alert variant="error" :message="$error" />
    @endforeach

    @if ($outstanding !== null && $party)
        <x-ui.alert variant="info"
            :title="'مانده قابل بازپرداخت به «'.$party->name.'»'"
            :message="number_format($outstanding).' تومان. بیشتر از این مبلغ قابل پرداخت نیست؛ بازپرداخت مازاد، بدهی را تسویه نمی‌کند بلکه آن را منفی می‌کند.'" />
    @endif

    <form method="POST" action="{{ route('expenses.reimbursements.store') }}" class="space-y-4">
        @csrf

        <x-common.component-card title="نوع بازپرداخت">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}">نوع</label>
                    <select name="type" class="{{ $selectClass }}"
                            onchange="window.location = '{{ route('expenses.reimbursements.create') }}?type=' + this.value">
                        @foreach ($types as $t)
                            <option value="{{ $t['value'] }}" @selected($t['value'] === $type->value)>{{ $t['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                        حساب بدهکارشونده: {{ $current['balance_label'] }} ({{ $current['account'] }})
                    </p>
                </div>

                {{-- The one party picker: server-side search over every party, filtered to
                     the role this reimbursement type requires. A merged party is never
                     offered — the endpoint excludes it. --}}
                <div>
                    <x-form.party-select name="party_id" label="طرف حساب"
                        :value="$party?->id" :selectedName="$party?->name"
                        :role="$current['role']" required />
                </div>
            </div>
        </x-common.component-card>

        <x-common.component-card title="پرداخت">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.money-input name="amount" label="مبلغ بازپرداخت" required />

                <div>
                    <label class="{{ $labelClass }}">از حساب</label>
                    <select name="bank_account_id" required class="{{ $selectClass }}">
                        <option value="">انتخاب کنید…</option>
                        @foreach ($banks as $bank)
                            <option value="{{ $bank->id }}" @selected(old('bank_account_id') == $bank->id)>{{ $bank->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-form.jalali-date name="accounting_date" label="تاریخ سند" :value="$today" required />

                <div>
                    <label class="{{ $labelClass }}">مرجع / شماره پیگیری</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" class="{{ $inputClass }}">
                </div>

                @if ($expenses->isNotEmpty())
                    <div class="sm:col-span-2">
                        <label class="{{ $labelClass }}">هزینه مرتبط (اختیاری)</label>
                        <select name="expense_id" class="{{ $selectClass }}">
                            <option value="">— بدون ارجاع به هزینه مشخص —</option>
                            @foreach ($expenses as $e)
                                <option value="{{ $e['id'] }}" @selected(old('expense_id') == $e['id'])>{{ $e['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            فقط برای ارجاع و پیگیری. سقف بازپرداخت، مانده حساب این شخص است — نه مبلغ این هزینه.
                        </p>
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">یادداشت</label>
                    <textarea name="notes" rows="2" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">{{ old('notes') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit"
                    class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                    {{ $current['label'] }}
                </button>
                <p class="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                    این عملیات هیچ هزینه جدیدی ثبت نمی‌کند: بدهی شرکت به این شخص کم و از حساب بانکی برداشت می‌شود.
                </p>
            </div>
        </x-common.component-card>
    </form>
</div>
@endsection
