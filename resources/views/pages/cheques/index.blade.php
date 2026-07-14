@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $headers = [
        ['label' => 'شماره چک'],
        ['label' => 'طرف حساب'],
        ['label' => 'نوع'],
        ['label' => 'مبلغ', 'align' => 'end'],
        ['label' => 'تاریخ سررسید'],
        ['label' => 'بانک'],
        ['label' => 'وضعیت'],
    ];

    $selectClass = 'h-9 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="چک‌ها" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="direction" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه انواع</option>
                @foreach ($directions as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['direction'] ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="status" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه وضعیت‌ها</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </form>

        <a href="{{ route('cheques.create') }}"
           class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600">
            ثبت چک جدید
        </a>
    </div>

    <x-common.component-card title="چک‌های ثبت‌شده">
        <x-tables.data-table :headers="$headers" :paginator="$cheques">
            @forelse ($cheques as $cheque)
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ route('cheques.show', $cheque) }}" class="font-medium text-brand-500 hover:underline">
                            <x-tables.ltr :value="$cheque->serial ?? '—'" :cell="false" tone="brand" />
                        </a>
                    </td>
                    <td class="px-5 py-3 text-gray-700 sm:px-6 dark:text-gray-300">{{ $cheque->party->name }}</td>
                    <td class="whitespace-nowrap px-5 py-3 text-gray-700 sm:px-6 dark:text-gray-300">{{ $cheque->directionLabel() }}</td>
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$cheque->amount" type="toman" />
                    <td class="whitespace-nowrap px-5 py-3 text-sm sm:px-6">
                        <span class="{{ $cheque->isLate() ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-600 dark:text-gray-400' }}">
                            {{ JalaliPeriod::fmtDate($cheque->due_date) }}
                        </span>
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-400">
                        {{ $cheque->bankAccount?->name ?? $cheque->bank_name ?? '—' }}
                    </td>
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.status :status="$cheque->badgeStatus()" :label="$cheque->statusLabel()" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="px-5 py-10">
                        <x-states.state variant="empty"
                            title="هنوز چکی ثبت نشده است"
                            message="چک دریافتی از مشتری و چک پرداختی به تأمین‌کننده از این‌جا ثبت و پیگیری می‌شوند." />
                    </td>
                </tr>
            @endforelse
        </x-tables.data-table>
    </x-common.component-card>
</div>
@endsection
