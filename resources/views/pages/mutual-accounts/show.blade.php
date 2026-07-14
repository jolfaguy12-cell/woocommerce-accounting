@extends('layouts.app')

@php
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $entry = $offset->journalEntry;
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="تهاتر" parentLabel="حساب‌های دوطرفه" :parentUrl="route('mutual-accounts.index')" />

<div class="mx-auto max-w-4xl space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif
    @if ($errors->any())
        <x-ui.alert variant="error" title="انجام نشد" :message="$errors->first()" />
    @endif

    <x-common.component-card :title="$offset->type->label()">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                <div class="mt-1">
                    <x-ui.status :status="$offset->operationStatus()->badgeStatus()" :label="$offset->operationStatus()->label()" />
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">طرف حساب</p>
                <a href="{{ route('parties.show', $offset->party) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">
                    {{ $offset->party->name }}
                </a>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">مبلغ تهاتر</p>
                <x-tables.num :value="$offset->amount" type="toman" :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">تاریخ</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDate($offset->offset_date) }}
                </p>
            </div>
            <div class="sm:col-span-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">دلیل</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $offset->reason }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">کد پیگیری</p>
                <x-tables.ltr :value="$offset->reference" :cell="false" class="mt-1 block text-sm" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">ثبت‌کننده</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $offset->creator?->name ?? '—' }}</p>
            </div>
        </div>

        @if ($offset->isReversed())
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل برگشت: {{ $offset->reversal_reason }}
            </p>
        @endif
        @if ($offset->isCancelled())
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل لغو: {{ $offset->cancel_reason }}
            </p>
        @endif
    </x-common.component-card>

    <x-common.component-card title="مانده‌های فعلی این طرف حساب">
        @if (empty($balances))
            <x-states.state variant="empty" title="مانده‌ای وجود ندارد" />
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($balances as $balance)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $balance['label'] }}</p>
                        <x-tables.num :value="$balance['amount']" type="toman" :cell="false"
                            :tone="$balance['direction'] === 'due_to_us' ? 'positive' : 'default'"
                            class="mt-1 block text-sm font-medium" />
                    </div>
                @endforeach
            </div>
        @endif
    </x-common.component-card>

    <x-common.component-card title="سند حسابداری">
        @if ($entry)
            <x-tables.data-table :headers="[
                ['label' => 'حساب'],
                ['label' => 'شرح'],
                ['label' => 'بدهکار', 'align' => 'end'],
                ['label' => 'بستانکار', 'align' => 'end'],
            ]">
                @foreach ($entry->lines as $line)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-800 sm:px-6 dark:text-white/90">
                            {{ $line->account->code }} — {{ $line->account->name }}
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-600 sm:px-6 dark:text-gray-400">{{ $line->memo ?? '—' }}</td>
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$line->debit > 0 ? $line->debit : null" type="toman" tone="positive" />
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$line->credit > 0 ? $line->credit : null" type="toman" tone="negative" />
                    </tr>
                @endforeach
            </x-tables.data-table>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                هر دو سطر روی همین طرف حساب ثبت شده‌اند — تهاتر، بدهی یک نفر را به نام دیگری منتقل نمی‌کند.
            </p>
        @else
            <x-states.state variant="empty" title="هنوز سندی صادر نشده"
                message="تا زمانی که این تهاتر تأیید و ثبت نشود، هیچ اثری در دفترکل ندارد." />
        @endif
    </x-common.component-card>

    @if ($canApprove || $canReverse || $canCancel)
        <x-common.component-card title="کنترل‌ها">
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($canApprove)
                    <form method="POST" action="{{ route('mutual-accounts.approve', $offset) }}">
                        @csrf
                        <p class="mb-2 text-sm text-gray-600 dark:text-gray-400">با تأیید، سند این تهاتر همین حالا در دفترکل ثبت می‌شود.</p>
                        <button class="h-10 w-full rounded-lg bg-success-500 text-sm font-medium text-white hover:bg-success-600">تأیید و ثبت سند</button>
                    </form>
                @endif

                @if ($canCancel)
                    <form method="POST" action="{{ route('mutual-accounts.cancel', $offset) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل لغو" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            لغو تهاتر (بدون اثر مالی)
                        </button>
                    </form>
                @endif

                @if ($canReverse)
                    <form method="POST" action="{{ route('mutual-accounts.reverse', $offset) }}" class="space-y-2 sm:col-span-2">
                        @csrf
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            برگشت، سند اصلی را حذف یا اصلاح نمی‌کند؛ یک سند معکوس صادر می‌شود و هر دو مانده به حالت قبل بازمی‌گردند.
                        </p>
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل برگشت (الزامی)" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg bg-error-500 text-sm font-medium text-white hover:bg-error-600">برگشت تهاتر</button>
                    </form>
                @endif
            </div>
        </x-common.component-card>
    @elseif ($offset->isPendingApproval())
        <x-ui.alert variant="info" title="در انتظار تأیید"
            message="این تهاتر باید توسط کاربر دیگری تأیید شود؛ ثبت‌کننده نمی‌تواند عملیات خودش را تأیید کند." />
    @endif
</div>
@endsection
