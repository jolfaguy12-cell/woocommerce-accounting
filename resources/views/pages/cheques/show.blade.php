@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';

    $entryHeaders = [
        ['label' => 'حساب'],
        ['label' => 'شرح'],
        ['label' => 'بدهکار', 'align' => 'end'],
        ['label' => 'بستانکار', 'align' => 'end'],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$cheque->directionLabel()" parentLabel="چک‌ها" :parentUrl="route('cheques.index')" />

<div class="mx-auto max-w-4xl space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif
    @if ($errors->any())
        <x-ui.alert variant="error" title="انجام نشد" :message="$errors->first()" />
    @endif

    <x-common.component-card :title="$cheque->directionLabel().' — '.$cheque->party->name">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                <div class="mt-1">
                    <x-ui.status :status="$cheque->badgeStatus()" :label="$cheque->statusLabel()" />
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">طرف حساب</p>
                <a href="{{ route('parties.show', $cheque->party) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">
                    {{ $cheque->party->name }}
                </a>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">مبلغ</p>
                <x-tables.num :value="$cheque->amount" type="toman" :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">تاریخ سررسید</p>
                <p class="mt-1 text-sm font-medium {{ $cheque->isLate() ? 'text-error-600 dark:text-error-400' : 'text-gray-800 dark:text-white/90' }}">
                    {{ JalaliPeriod::fmtDateTime($cheque->due_date) }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شماره چک</p>
                <x-tables.ltr :value="$cheque->serial" :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">بانک</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    {{ $cheque->bankAccount?->name ?? $cheque->bank_name ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">شرح</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $cheque->description ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">ثبت‌کننده</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $cheque->creator?->name ?? '—' }}</p>
            </div>
        </div>

        @if ($cheque->status === \App\Domain\Receivables\Models\Cheque::CANCELLED)
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل ابطال: {{ $cheque->cancel_reason }}
            </p>
        @endif
    </x-common.component-card>

    @if ($canSettle)
        <x-common.component-card title="تعیین وضعیت چک">
            <div class="grid gap-4 sm:grid-cols-2">
                <form method="POST" action="{{ route('cheques.clear', $cheque) }}" class="space-y-2">
                    @csrf
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $cheque->isReceivable()
                            ? 'با وصول، وجه چک به حساب انتخاب‌شده وارد می‌شود.'
                            : 'با پاس شدن، وجه چک از حساب انتخاب‌شده خارج می‌شود.' }}
                    </p>
                    <select name="bank_account_id" required class="{{ $selectClass }}">
                        @foreach ($bankAccounts as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <button class="h-10 w-full rounded-lg bg-success-500 text-sm font-medium text-white hover:bg-success-600">
                        {{ $cheque->isReceivable() ? 'ثبت وصول چک' : 'ثبت پاس شدن چک' }}
                    </button>
                </form>

                <form method="POST" action="{{ route('cheques.bounce', $cheque) }}" class="space-y-2">
                    @csrf
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        با برگشت خوردن چک، بدهی/طلب دقیقاً به وضعیت پیش از چک بازمی‌گردد. طلب از بین نمی‌رود.
                    </p>
                    <button class="h-10 w-full rounded-lg bg-error-500 text-sm font-medium text-white hover:bg-error-600">
                        ثبت برگشت چک
                    </button>
                </form>
            </div>
        </x-common.component-card>
    @endif

    @if ($canCancel || $canReverse)
        <x-common.component-card title="کنترل‌ها">
            @if ($canCancel)
                <form method="POST" action="{{ route('cheques.cancel', $cheque) }}" class="space-y-2">
                    @csrf
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        ابطال برای چکی است که اصلاً نباید ثبت می‌شد. سند ثبت اولیه حذف نمی‌شود؛ سند معکوس آن صادر می‌شود
                        و بدهی/طلب طرف حساب به حالت اول برمی‌گردد.
                    </p>
                    <input type="text" name="reason" required maxlength="255" placeholder="دلیل ابطال (الزامی)" class="{{ $inputClass }}">
                    <button class="h-10 w-full rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        ابطال چک
                    </button>
                </form>
            @endif

            @if ($canReverse)
                <form method="POST" action="{{ route('cheques.reverse', $cheque) }}" class="space-y-2">
                    @csrf
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        اگر وصول یا برگشت این چک اشتباه ثبت شده، با برگشت تسویه، سند آن معکوس می‌شود و چک دوباره «در جریان» می‌شود.
                    </p>
                    <input type="text" name="reason" required maxlength="255" placeholder="دلیل برگشت تسویه (الزامی)" class="{{ $inputClass }}">
                    <button class="h-10 w-full rounded-lg bg-error-500 text-sm font-medium text-white hover:bg-error-600">
                        برگشت تسویهٔ چک
                    </button>
                </form>
            @endif
        </x-common.component-card>
    @endif

    <x-common.component-card title="سند ثبت چک">
        @if ($cheque->journalEntry)
            <x-tables.data-table :headers="$entryHeaders">
                @foreach ($cheque->journalEntry->lines as $line)
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
            <x-states.state variant="empty" title="سندی یافت نشد" />
        @endif
    </x-common.component-card>

    @if ($cheque->settlementEntry)
        <x-common.component-card title="سند تسویه چک">
            <x-tables.data-table :headers="$entryHeaders">
                @foreach ($cheque->settlementEntry->lines as $line)
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
        </x-common.component-card>
    @endif
</div>
@endsection
