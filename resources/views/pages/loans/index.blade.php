@extends('layouts.app')

@php
    $headers = [
        ['label' => 'طرف حساب'],
        ['label' => 'نوع'],
        ['label' => 'مبلغ اصل وام', 'align' => 'end'],
        ['label' => 'مبلغ پرداخت‌شده', 'align' => 'end'],
        ['label' => 'مانده اصل وام', 'align' => 'end'],
        ['label' => 'تاریخ سررسید'],
        ['label' => 'حساب بانکی یا صندوق'],
        ['label' => 'وضعیت'],
    ];

    $selectClass = 'h-9 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="وام و اقساط" />

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
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </form>

        <a href="{{ route('loans.create') }}"
           class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600">
            ثبت وام جدید
        </a>
    </div>

    <x-common.component-card title="وام‌های ثبت‌شده">
        <x-tables.data-table :headers="$headers" :paginator="$loans">
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ $row['url'] }}" class="font-medium text-brand-500 hover:underline">{{ $row['party'] }}</a>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['received_at_fa'] }}</p>
                    </td>
                    <td class="whitespace-nowrap px-5 py-3 text-gray-700 sm:px-6 dark:text-gray-300">{{ $row['direction'] }}</td>
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['principal']" type="toman" />
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['paid_total']" type="toman" zero tone="muted" />
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$row['remaining_principal']" type="toman" zero
                        :tone="$row['remaining_principal'] > 0 ? 'default' : 'positive'" />
                    <td class="whitespace-nowrap px-5 py-3 text-sm sm:px-6">
                        <span class="{{ $row['is_overdue'] ? 'font-medium text-error-600 dark:text-error-400' : 'text-gray-600 dark:text-gray-400' }}">
                            {{ $row['next_due_fa'] ?? $row['maturity_fa'] ?? '—' }}
                        </span>
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-400">{{ $row['bank'] ?? '—' }}</td>
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.status :status="$row['status']" :label="$row['status_label']" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="px-5 py-10">
                        <x-states.state variant="empty"
                            title="هنوز وامی ثبت نشده است"
                            message="وام دریافتی (از بانک یا شریک) و وام پرداختی (به شریک یا کارمند) از این‌جا ثبت می‌شوند." />
                    </td>
                </tr>
            @endforelse
        </x-tables.data-table>
    </x-common.component-card>
</div>
@endsection
