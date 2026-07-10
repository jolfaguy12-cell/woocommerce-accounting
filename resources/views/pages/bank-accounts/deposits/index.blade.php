@extends('layouts.app')

@php
    $columns = ['وضعیت', 'شناسه مرجع', 'تاریخ واریز', 'نوع (PSP)', 'مبلغ کل', 'نام صاحب حساب', 'حساب مقصد', 'سند حسابداری'];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="واریزی‌های زیبال" />

<div class="space-y-4" x-data>
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex justify-end">
        <button @click="$dispatch('open-import-deposits-modal')" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + ایمپورت فایل واریزی‌ها
        </button>
    </div>

    <form method="GET" action="{{ route('deposits.index') }}">
        <x-tables.pro-table
            :headers="$columns"
            :paginator="$deposits"
            emptyMessage="واریزی‌ای با این فیلترها یافت نشد"
            search-value="{{ $filters['search'] ?? '' }}"
            search-placeholder="جستجوی شناسه مرجع، نام صاحب حساب یا شناسه پیگیری"
            with-date-range
            date-from-value="{{ $filters['date_from'] ?? null }}"
            date-to-value="{{ $filters['date_to'] ?? null }}"
            :clear-filters-route="array_filter($filters) ? route('deposits.index') : null"
            :totals="[['label' => 'مبلغ کل (فیلتر فعلی)', 'value' => number_format($totalAmount).' تومان']]"
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
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.badge size="sm" :color="$deposit->status === 'موفق' ? 'success' : 'warning'">{{ $deposit->status ?? '—' }}</x-ui.badge>
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300" dir="ltr">{{ $deposit->external_reference }}</td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($deposit->deposited_at) }}</td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $deposit->psp_label ?? '—' }}</td>
                    <td class="px-5 py-3 font-medium text-gray-800 sm:px-6 dark:text-white/90" dir="ltr">{{ number_format($deposit->amount_toman) }} تومان</td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-300">{{ $deposit->account_holder_name ?? '—' }}</td>
                    <td class="px-5 py-3 sm:px-6">
                        @if ($deposit->bankAccount)
                            <a href="{{ route('bank-accounts.show', $deposit->bankAccount) }}" class="text-brand-500 hover:underline">{{ $deposit->bankAccount->name }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-5 py-3 sm:px-6">
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
    </form>
</div>

{{-- Import modal --}}
<x-ui.modal :isOpen="$errors->any()" @open-import-deposits-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('deposits.import') }}" enctype="multipart/form-data">
        @csrf
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ایمپورت فایل واریزی‌های زیبال</h4>

        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            فایل اکسل «گزارش تسویه» را از پنل زیبال دریافت کنید و همینجا آپلود کنید. ردیف‌های تکراری خودکار نادیده گرفته می‌شوند و می‌توانید فایل‌ها را چندبار آپلود کنید.
        </p>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">فایل (xlsx / xls / csv)</label>
        <input type="file" name="file" required accept=".xlsx,.xls,.csv"
            class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-600 dark:text-gray-300">
        @error('file')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">آپلود و ایمپورت</button>
        </div>
    </form>
</x-ui.modal>
@endsection
