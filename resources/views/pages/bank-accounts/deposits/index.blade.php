@extends('layouts.app')

@php
    $columns = [
        ['key' => 'status', 'label' => 'وضعیت', 'sort' => 'status'],
        ['key' => 'reference', 'label' => 'شناسه مرجع'],
        ['key' => 'deposited_at', 'label' => 'تاریخ واریز', 'sort' => 'deposited_at'],
        ['key' => 'psp', 'label' => 'نوع (PSP)'],
        ['key' => 'amount', 'label' => 'مبلغ کل', 'sort' => 'amount'],
        ['key' => 'holder', 'label' => 'نام صاحب حساب'],
        ['key' => 'bank_account', 'label' => 'حساب مقصد'],
        ['key' => 'posted', 'label' => 'سند حسابداری'],
    ];

    $filterLabels = [
        'status' => 'وضعیت',
        'psp' => 'نوع (PSP)',
        'bank_account_id' => 'حساب مقصد',
        'date_from' => 'از تاریخ',
        'date_to' => 'تا تاریخ',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="واریزی‌های زیبال" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif
    @if (session('warning'))
        <x-ui.alert variant="warning" :message="session('warning')" />
    @endif

    {{-- No API exists to pull Zibal settlements automatically (see plan notes) — this is the
         fastest manual path: pick the exported file and it imports immediately, no extra click. --}}
    <form method="POST" action="{{ route('deposits.import') }}" enctype="multipart/form-data" class="rounded-2xl border border-dashed border-gray-300 bg-white p-4 dark:border-gray-700 dark:bg-white/[0.03]">
        @csrf
        <label class="flex cursor-pointer items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-800 dark:text-white/90">ایمپورت فایل واریزی‌های زیبال</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">فایل «گزارش تسویه» را از پنل زیبال دریافت و اینجا انتخاب کنید — با انتخاب فایل بلافاصله ایمپورت می‌شود. ردیف‌های تکراری خودکار نادیده گرفته می‌شوند.</p>
                @error('file')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>
            <input type="file" name="file" required accept=".xlsx,.xls,.csv" onchange="this.form.submit()"
                class="block text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-600 dark:text-gray-300">
        </label>
    </form>

    <x-tables.pro-table
        :columns="$columns"
        :paginator="$deposits"
        :query="$query"
        :filterLabels="$filterLabels"
        emptyMessage="هنوز واریزی‌ای ایمپورت نشده است"
        search-value="{{ $filters['search'] ?? '' }}"
        search-placeholder="جستجوی شناسه مرجع، نام صاحب حساب یا شناسه پیگیری"
        with-date-range
        date-from-value="{{ $filters['date_from'] ?? null }}"
        date-to-value="{{ $filters['date_to'] ?? null }}"
        :clear-filters-route="$query->hasActiveFilters() ? $query->clearUrl() : null"
        :totals="[['label' => 'مبلغ کل (فیلتر فعلی)', 'value' => number_format($totalAmount).' تومان']]"
        storage-key="deposits.visibleColumns"
    >
        <x-slot:filters>
            <select name="bank_account_id" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">همه حساب‌ها</option>
                @foreach ($bankAccounts as $account)
                    <option value="{{ $account->id }}" @selected(($filters['bank_account_id'] ?? null) == $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>

            <select name="status" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">همه وضعیت‌ها</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $status }}</option>
                @endforeach
            </select>

            <select name="psp" onchange="this.form.submit()" class="h-9 rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">همه انواع (PSP)</option>
                @foreach ($pspLabels as $psp)
                    <option value="{{ $psp }}" @selected(($filters['psp'] ?? null) === $psp)>{{ $psp }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        @forelse ($deposits as $deposit)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.status" class="px-5 py-3 sm:px-6">
                    <x-ui.badge size="sm" :color="$deposit->status === 'موفق' ? 'success' : 'warning'">{{ $deposit->status ?? '—' }}</x-ui.badge>
                </td>
 <x-tables.ltr x-show="visible.reference" class="px-5 py-3 sm:px-6" :value="$deposit->external_reference" tone="muted" />
                <td x-show="visible.deposited_at" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($deposit->deposited_at) }}</td>
                <td x-show="visible.psp" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $deposit->psp_label ?? '—' }}</td>
 <x-tables.num x-show="visible.amount" class="px-5 py-3 font-medium sm:px-6" :value="$deposit->amount_toman" type="toman" />
                <td x-show="visible.holder" class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $deposit->account_holder_name ?? '—' }}</td>
                <td x-show="visible.bank_account" class="px-5 py-3 sm:px-6">
                    @if ($deposit->bankAccount)
                        <a href="{{ route('bank-accounts.show', $deposit->bankAccount) }}" class="text-brand-500 hover:underline">{{ $deposit->bankAccount->name }}</a>
                    @else
                        —
                    @endif
                </td>
                <td x-show="visible.posted" class="px-5 py-3 sm:px-6">
                    @if ($deposit->isPosted())
                        <x-ui.badge size="sm" color="success">ثبت شد</x-ui.badge>
                    @else
                        <x-ui.badge size="sm" color="light">ثبت نشد</x-ui.badge>
                    @endif
                </td>
            </tr>
        @empty
        @endforelse
    </x-tables.pro-table>
</div>
@endsection
