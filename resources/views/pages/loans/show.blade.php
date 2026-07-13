@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $inputClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90';
    $selectClass = 'h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $labelClass = 'mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-400';

    $scheduleHeaders = [
        ['label' => 'قسط'],
        ['label' => 'سررسید'],
        ['label' => 'اصل وام', 'align' => 'end'],
        ['label' => 'سود', 'align' => 'end'],
        ['label' => 'کارمزد', 'align' => 'end'],
        ['label' => 'جریمه دیرکرد', 'align' => 'end'],
        ['label' => 'جمع قسط', 'align' => 'end'],
        ['label' => 'وضعیت'],
        ['label' => ''],
    ];
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$loan->direction->label()" parentLabel="وام و اقساط" :parentUrl="route('loans.index')" />

<div class="mx-auto max-w-6xl space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif
    @if ($errors->any())
        <x-ui.alert variant="error" title="انجام نشد" :message="$errors->first()" />
    @endif

    <x-common.component-card :title="$loan->direction->label().' — '.$loan->party->name">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">وضعیت</p>
                <div class="mt-1">
                    <x-ui.status :status="$summary['status']" :label="$summary['status_label']" />
                </div>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">طرف حساب</p>
                <a href="{{ route('parties.show', $loan->party) }}" class="mt-1 block text-sm font-medium text-brand-500 hover:underline">
                    {{ $loan->party->name }}
                </a>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">اصل وام</p>
                <x-tables.num :value="$summary['principal']" type="toman" :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">مانده اصل وام</p>
                <x-tables.num :value="$summary['remaining_principal']" type="toman" zero :cell="false"
                    :tone="$summary['remaining_principal'] > 0 ? 'default' : 'positive'"
                    class="mt-1 block text-sm font-medium" />
            </div>

            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">سود</p>
                <x-tables.num :value="$summary['interest']" type="toman" zero :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">کارمزد</p>
                <x-tables.num :value="$summary['paid_fee']" type="toman" zero :cell="false" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">جریمه دیرکرد</p>
                <x-tables.num :value="$summary['paid_penalty']" type="toman" zero :cell="false"
                    :tone="$summary['paid_penalty'] > 0 ? 'negative' : 'muted'" class="mt-1 block text-sm font-medium" />
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">مبلغ پرداخت‌شده</p>
                <x-tables.num :value="$summary['paid_total']" type="toman" zero :cell="false" class="mt-1 block text-sm font-medium" />
            </div>

            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">سررسید بعدی</p>
                <p class="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{{ $summary['next_due_fa'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">سررسید نهایی</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $summary['maturity_fa'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">حساب بانکی</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $loan->bankAccount?->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">روش سود</p>
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $loan->interest_method->label() }}</p>
            </div>
        </div>

        @if ($loan->status->value === 'reversed')
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل برگشت: {{ $loan->reversal_reason }}
            </p>
        @endif
        @if ($loan->status->value === 'cancelled')
            <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
                دلیل لغو: {{ $loan->cancel_reason }}
            </p>
        @endif
    </x-common.component-card>

    <x-common.component-card title="برنامه اقساط">
        @if ($loan->installments->isEmpty())
            <x-states.state variant="empty" title="برنامهٔ اقساطی ثبت نشده است"
                message="این وام بدون برنامهٔ اقساط ثبت شده و یکجا تسویه می‌شود." />
        @else
            <x-tables.data-table :headers="$scheduleHeaders">
                @foreach ($loan->installments as $installment)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->sequence" type="int" />
                        <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600 sm:px-6 dark:text-gray-400">
                            {{ $installment->due_date ? JalaliPeriod::fmtDateTime($installment->due_date) : '—' }}
                        </td>
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->principal_part" type="toman" zero />
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->interest_part" type="toman" zero tone="muted" />
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->fee_part" type="toman" zero tone="muted" />
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->penalty_part" type="toman" zero
                            :tone="$installment->penalty_part > 0 ? 'negative' : 'muted'" />
                        <x-tables.num class="px-5 py-3 sm:px-6" :value="$installment->total()" type="toman" zero />
                        <td class="px-5 py-3 sm:px-6">
                            <x-ui.status :status="$installment->badgeStatus()" :label="$installment->statusLabel()" />
                        </td>
                        <td class="px-5 py-3 sm:px-6">
                            @if ($installment->isPaid() && $canReverse)
                                <form method="POST" action="{{ route('loans.installments.reverse', [$loan, $installment]) }}"
                                      class="flex items-center gap-1">
                                    @csrf
                                    <input type="text" name="reason" required maxlength="255" placeholder="دلیل برگشت"
                                           class="h-8 w-32 rounded-md border border-gray-300 px-2 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                    <button class="h-8 rounded-md border border-error-300 px-2 text-xs text-error-600 hover:bg-error-50 dark:border-error-500/40 dark:hover:bg-error-500/10">
                                        برگشت
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-tables.data-table>
        @endif
    </x-common.component-card>

    @if ($canPay)
        <x-common.component-card :title="$loan->isReceivable() ? 'ثبت دریافت قسط' : 'ثبت پرداخت قسط'">
            <form method="POST" action="{{ route('loans.installments.pay', $loan) }}" class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @csrf
                <div class="sm:col-span-3 lg:col-span-2">
                    <label class="{{ $labelClass }}">قسط</label>
                    <select name="installment_id" class="{{ $selectClass }}">
                        <option value="">پرداخت خارج از برنامه</option>
                        @foreach ($loan->installments->where('status', '!=', 'paid') as $i)
                            <option value="{{ $i->id }}">
                                قسط {{ $i->sequence }} — {{ number_format($i->total()) }} تومان
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">مبلغ کل</label>
                    <input type="number" name="amount" min="1" required dir="ltr" value="{{ old('amount') }}" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">اصل وام</label>
                    <input type="number" name="principal_part" min="0" required dir="ltr" value="{{ old('principal_part') }}" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">کارمزد</label>
                    <input type="number" name="fee_part" min="0" dir="ltr" value="{{ old('fee_part', 0) }}" class="{{ $inputClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">جریمه دیرکرد</label>
                    <input type="number" name="penalty_part" min="0" dir="ltr" value="{{ old('penalty_part', 0) }}" class="{{ $inputClass }}">
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">حساب بانکی</label>
                    <select name="bank_account_id" required class="{{ $selectClass }}">
                        @foreach ($bankAccounts as $b)
                            <option value="{{ $b->id }}" @selected($loan->bank_account_id === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $labelClass }}">تاریخ</label>
                    <input type="date" name="paid_at" value="{{ old('paid_at', $today) }}" dir="ltr" required class="{{ $inputClass }}">
                </div>
                <div class="flex items-end sm:col-span-2">
                    <button class="h-10 w-full rounded-lg bg-brand-500 text-sm font-medium text-white hover:bg-brand-600">
                        ثبت قسط
                    </button>
                </div>

                <p class="text-xs text-gray-500 sm:col-span-3 lg:col-span-6 dark:text-gray-400">
                    «سود» به‌صورت خودکار محاسبه می‌شود: هرچه از مبلغ کل پس از کسر اصل وام، کارمزد و جریمه دیرکرد باقی بماند، سود است.
                </p>
            </form>
        </x-common.component-card>
    @endif

    @if ($loan->journalEntry)
        <x-common.component-card title="سند حسابداری">
            <x-tables.data-table :headers="[
                ['label' => 'حساب'],
                ['label' => 'شرح'],
                ['label' => 'بدهکار', 'align' => 'end'],
                ['label' => 'بستانکار', 'align' => 'end'],
            ]">
                @foreach ($loan->journalEntry->lines as $line)
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

    @if ($canApprove || $canReverse || $canCancel)
        <x-common.component-card title="کنترل‌ها">
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($canApprove)
                    <form method="POST" action="{{ route('loans.approve', $loan) }}">
                        @csrf
                        <p class="mb-2 text-sm text-gray-600 dark:text-gray-400">با تأیید، سند این وام همین حالا در دفترکل ثبت می‌شود.</p>
                        <button class="h-10 w-full rounded-lg bg-success-500 text-sm font-medium text-white hover:bg-success-600">تأیید و ثبت سند</button>
                    </form>
                @endif

                @if ($canCancel)
                    <form method="POST" action="{{ route('loans.cancel', $loan) }}" class="space-y-2">
                        @csrf
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل لغو" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            لغو وام (بدون اثر مالی)
                        </button>
                    </form>
                @endif

                @if ($canReverse)
                    <form method="POST" action="{{ route('loans.reverse', $loan) }}" class="space-y-2 sm:col-span-2">
                        @csrf
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            برگشت، سند اصلی را حذف یا اصلاح نمی‌کند؛ یک سند معکوس صادر می‌شود و هر دو در تاریخچه می‌مانند.
                            اگر قسطی پرداخت شده باشد، ابتدا باید اقساط برگشت بخورند.
                        </p>
                        <input type="text" name="reason" required maxlength="255" placeholder="دلیل برگشت (الزامی)" class="{{ $inputClass }}">
                        <button class="h-10 w-full rounded-lg bg-error-500 text-sm font-medium text-white hover:bg-error-600">برگشت وام</button>
                    </form>
                @endif
            </div>
        </x-common.component-card>
    @endif
</div>
@endsection
