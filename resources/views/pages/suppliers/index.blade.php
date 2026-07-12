@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="تامین‌کننده‌ها" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-common.filter-bar>
            <input
                type="text"
                name="q"
                value="{{ $filters['q'] ?? '' }}"
                placeholder="جستجو نام / فروشگاه / تلفن"
                class="h-9 w-64 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
            >
            <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        </x-common.filter-bar>

        <button @click="$dispatch('open-add-supplier-modal')" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
            + تامین‌کننده جدید
        </button>
    </div>

    <x-tables.data-table
        :headers="['نام و نام خانوادگی', 'نام فروشگاه', 'شماره تلفن', 'شماره حساب']"
        :paginator="$suppliers"
        emptyMessage="هنوز تامین‌کننده‌ای ثبت نشده است"
    >
        @foreach ($suppliers as $supplier)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td class="p-3 sm:px-6">
                    <a href="{{ route('suppliers.show', $supplier) }}" class="text-brand-500 hover:underline">{{ $supplier->name }}</a>
                </td>
                <td class="px-5 text-gray-600 sm:px-6 dark:text-gray-300">{{ $supplier->shop_name ?? '—' }}</td>
 <x-tables.ltr class="px-5 sm:px-6" :value="$supplier->phone" tone="muted" />
 <x-tables.ltr class="px-5 sm:px-6" :value="$supplier->bank_account_number" tone="muted" />
            </tr>
        @endforeach
    </x-tables.data-table>
</div>

{{-- Add supplier modal --}}
<x-ui.modal :isOpen="$errors->any()" @open-add-supplier-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('suppliers.store') }}">
        @csrf
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">تامین‌کننده جدید</h4>

        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام و نام خانوادگی</label>
        <input type="text" name="name" required value="{{ old('name') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">نام فروشگاه (اختیاری)</label>
        <input type="text" name="shop_name" value="{{ old('shop_name') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('shop_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره تلفن (اختیاری)</label>
        <input type="text" name="phone" dir="ltr" value="{{ old('phone') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('phone')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">شماره حساب (اختیاری)</label>
        <input type="text" name="bank_account_number" dir="ltr" value="{{ old('bank_account_number') }}"
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('bank_account_number')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </div>
    </form>
</x-ui.modal>
@endsection
