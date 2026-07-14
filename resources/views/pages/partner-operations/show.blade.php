@extends('layouts.app')

@php
    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $entry = $operation->journalEntry;
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$operation->type->label()" parentLabel="عملیات شرکا" :parentUrl="route('partner-operations.index')" />

<div class="mx-auto max-w-4xl space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif
    @if ($errors->any())
        <x-ui.alert variant="error" title="انجام نشد" :message="$errors->first()" />
    @endif

    <x-common.component-card :title="$operation->type->label()">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                <div class="mt-1">
                    <x-ui.status :status="$operation->operationStatus()->badgeStatus()" :label="$operation->operationStatus()->label()" />
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شریک</p>
                <a href="{{ route('parties.show', $operation->party) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">
                    {{ $operation->party->name }}
                </a>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">مبلغ</p>
                <x-tables.num :value="$operation->amount" type="toman" :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">تاریخ</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">
                    {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDate($operation->operation_date) }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">حساب بانکی</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    {{ $operation->bankAccount?->name ?? 'بدون جابه‌جایی وجه' }}
                </p>
            </div>
            <div class="sm:col-span-2">
                <p class="text-xs text-gray-500 dark:text-gray-400">شرح</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $operation->description }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">ثبت‌کننده</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $operation->creator?->name ?? '—' }}</p>
            </div>
            @if ($operation->loan)
                {{-- A partner loan IS a loan: its schedule, its repayments and its
                     outstanding principal all live on the loan, not here. --}}
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">قرارداد وام</p>
                    <a href="{{ route('loans.show', $operation->loan) }}"
                       class="mt-1 block text-sm font-medium text-brand-500 hover:underline">
                        مشاهده وام و برنامه اقساط
                    </a>
                </div>
            @endif
        </div>

        @if ($operation->isReversed())
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل برگشت: {{ $operation->reversal_reason }}
            </p>
        @endif
        @if ($operation->isCancelled())
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل لغو: {{ $operation->cancel_reason }}
            </p>
        @endif
    </x-common.component-card>

    <x-common.component-card title="وضعیت فعلی شریک">
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
        @else
            <x-states.state variant="empty" title="هنوز سندی صادر نشده"
                message="تا زمانی که این عملیات تأیید و ثبت نشود، هیچ اثری در دفترکل ندارد." />
        @endif
    </x-common.component-card>

    @if ($canApprove || $canReverse || $canCancel)
        <x-common.component-card title="کنترل‌ها">
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($canApprove)
                    <form method="POST" action="{{ route('partner-operations.approve', $operation) }}">
                        @csrf
                        <p class="mb-2 text-sm text-gray-600 dark:text-gray-400">با تأیید، سند این عملیات همین حالا در دفترکل ثبت می‌شود.</p>
                        <button class="h-10 w-full rounded-lg bg-success-500 text-sm font-medium text-white hover:bg-success-600">تأیید و ثبت سند</button>
                    </form>
                @endif

                @if ($canCancel)
                    <form method="POST" action="{{ route('partner-operations.cancel', $operation) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل لغو" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            لغو عملیات (بدون اثر مالی)
                        </button>
                    </form>
                @endif

                @if ($canReverse)
                    <form method="POST" action="{{ route('partner-operations.reverse', $operation) }}" class="space-y-2 sm:col-span-2">
                        @csrf
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            برگشت، سند اصلی را حذف یا اصلاح نمی‌کند؛ یک سند معکوس صادر می‌شود و هر دو در تاریخچه می‌مانند.
                        </p>
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل برگشت (الزامی)" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg bg-error-500 text-sm font-medium text-white hover:bg-error-600">برگشت عملیات</button>
                    </form>
                @endif
            </div>
        </x-common.component-card>
    @elseif ($operation->isPendingApproval())
        <x-ui.alert variant="info" title="در انتظار تأیید"
            message="این عملیات باید توسط کاربر دیگری تأیید شود؛ ثبت‌کننده نمی‌تواند عملیات خودش را تأیید کند." />
    @endif
</div>
@endsection
