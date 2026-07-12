@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="حساب‌ها" />

<div class="space-y-4" x-data="{ visible: { name: true, bank_name: true, card_number: true, iban: true, balance: true, actions: true } }">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex justify-end">
        <button @click="$dispatch('open-add-bank-account-modal')" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + حساب جدید
        </button>
    </div>

    <x-tables.data-table
        :headers="[
            ['key' => 'name', 'label' => 'نام'],
            ['key' => 'bank_name', 'label' => 'نام بانک'],
            ['key' => 'card_number', 'label' => 'شماره کارت'],
            ['key' => 'iban', 'label' => 'شماره شبا'],
            ['key' => 'balance', 'label' => 'موجودی فعلی'],
            ['key' => 'actions', 'label' => ''],
        ]"
        :paginator="null"
        emptyMessage="هنوز حسابی ثبت نشده است"
    >
        @forelse ($accounts as $row)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.name" class="p-3 sm:px-6">
                    <a href="{{ route('bank-accounts.show', $row['model']) }}" class="text-brand-500 hover:underline">{{ $row['model']->name }}</a>
                    @if ($row['model']->is_cash)
                        <x-ui.badge color="light" size="sm">صندوق</x-ui.badge>
                    @endif
                </td>
                <td x-show="visible.bank_name" class="px-5 text-center text-gray-600 sm:px-6 dark:text-gray-300">{{ $row['model']->bank_name ?? '—' }}</td>
                <x-tables.ltr x-show="visible.card_number" class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" :value="$row['model']->card_number" />
                <x-tables.ltr x-show="visible.iban" class="px-5 text-gray-600 sm:px-6 dark:text-gray-300" :value="$row['model']->iban" />
                <x-tables.num x-show="visible.balance" class="px-5 sm:px-6 font-medium {{ $row['balance'] < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" :value="$row['balance']" type="toman" />
                <td x-show="visible.actions" class="px-5 text-center sm:px-6">
                    <button type="button" onclick="editBankAccount({{ $row['model']->id }}, {{ \Illuminate\Support\Js::from($row['model']->name) }}, {{ \Illuminate\Support\Js::from($row['model']->bank_name) }}, {{ \Illuminate\Support\Js::from($row['model']->card_number) }}, {{ \Illuminate\Support\Js::from($row['model']->iban) }})" class="text-sm text-brand-500 hover:underline">
                        ویرایش
                    </button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">هنوز حسابی ثبت نشده است</td>
            </tr>
        @endforelse
    </x-tables.data-table>
</div>

{{-- Add bank account modal --}}
<x-ui.modal :isOpen="$errors->any() || $openCreate" @open-add-bank-account-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('bank-accounts.store') }}">
        @csrf
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">حساب جدید</h4>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام حساب</label>
        <input type="text" name="name" required value="{{ old('name') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">نام بانک (اختیاری)</label>
        <input type="text" name="bank_name" value="{{ old('bank_name') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('bank_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره کارت (اختیاری)</label>
        <input type="text" name="card_number" dir="ltr" value="{{ old('card_number') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('card_number')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره شبا (اختیاری)</label>
        <input type="text" name="iban" dir="ltr" value="{{ old('iban') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('iban')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="is_cash" value="1" @checked(old('is_cash'))>
            این یک صندوق نقدی است (نه حساب بانکی)
        </label>

        <p class="mt-3 text-xs text-gray-400">موجودی حساب ذخیره نمی‌شود؛ همیشه از روی تراکنش‌های ثبت‌شده محاسبه می‌شود.</p>

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </div>
    </form>
</x-ui.modal>

{{--
    Single shared edit modal populated by editBankAccount() below, instead of
    one modal per row — same pattern as warehouse/packaging-cost.blade.php.
    is_cash isn't editable here since it decides the ledger account's parent
    (1000 cash vs 1100 bank) and changing it after the fact is a structural
    move, not a form edit.
--}}
<x-ui.modal x-data="{ open: false }" @open-edit-bank-account-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" id="edit-bank-account-form">
        @csrf
        @method('PUT')
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ویرایش حساب</h4>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام حساب</label>
        <input type="text" id="edit-bank-account-name" name="name" required
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">نام بانک (اختیاری)</label>
        <input type="text" id="edit-bank-account-bank-name" name="bank_name"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره کارت (اختیاری)</label>
        <input type="text" id="edit-bank-account-card-number" name="card_number" dir="ltr"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره شبا (اختیاری)</label>
        <input type="text" id="edit-bank-account-iban" name="iban" dir="ltr"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </div>
    </form>
</x-ui.modal>

<script>
    function editBankAccount(id, name, bankName, cardNumber, iban) {
        document.getElementById('edit-bank-account-form').action = '{{ url('bank-accounts') }}/' + id;
        document.getElementById('edit-bank-account-name').value = name ?? '';
        document.getElementById('edit-bank-account-bank-name').value = bankName ?? '';
        document.getElementById('edit-bank-account-card-number').value = cardNumber ?? '';
        document.getElementById('edit-bank-account-iban').value = iban ?? '';
        window.dispatchEvent(new CustomEvent('open-edit-bank-account-modal'));
    }
</script>
@endsection
