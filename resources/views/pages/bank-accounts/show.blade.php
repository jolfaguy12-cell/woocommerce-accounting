@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb :pageTitle="$bankAccount->name" />

<div class="space-y-4">
    <x-common.component-card :title="$bankAccount->name">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">نام بانک</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $bankAccount->bank_name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره کارت</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $bankAccount->card_number ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره شبا</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90" dir="ltr">{{ $bankAccount->iban ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">موجودی فعلی</p>
                <p class="mt-1 text-sm font-medium {{ $balance < 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}" dir="ltr">{{ number_format($balance) }} تومان</p>
            </div>
        </div>
    </x-common.component-card>

    <x-common.component-card title="تراکنش‌های حساب">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">تاریخ</th>
                        <th class="text-right font-normal">شرح</th>
                        <th class="text-right font-normal">طرف حساب</th>
                        <th class="text-right font-normal">بدهکار</th>
                        <th class="text-right font-normal">بستانکار</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $line)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <td class="py-2 text-gray-800 dark:text-white/90" dir="ltr">{{ \Morilog\Jalali\Jalalian::fromCarbon($line->entry->entry_date)->format('Y/m/d') }}</td>
                            <td class="text-gray-800 dark:text-white/90">{{ $line->entry->description }}</td>
                            <td class="text-gray-600 dark:text-gray-300">{{ $line->party->name ?? '—' }}</td>
                            <td class="text-success-600 dark:text-success-400" dir="ltr">{{ $line->debit > 0 ? number_format($line->debit) : '—' }}</td>
                            <td class="text-error-500" dir="ltr">{{ $line->credit > 0 ? number_format($line->credit) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-gray-400">هنوز تراکنشی برای این حساب ثبت نشده است.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-common.component-card>
</div>
@endsection
