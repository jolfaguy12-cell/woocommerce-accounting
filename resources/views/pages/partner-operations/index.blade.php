@extends('layouts.app')

@php
    $headers = [
        ['label' => 'تاریخ'],
        ['label' => 'شریک'],
        ['label' => 'نوع عملیات'],
        ['label' => 'مبلغ', 'align' => 'end'],
        ['label' => 'حساب'],
        ['label' => 'وضعیت'],
        ['label' => 'ثبت‌کننده'],
    ];

    $selectClass = 'h-9 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="عملیات شرکا" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="type" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه انواع</option>
                @foreach ($types as $key => $label)
                    <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="status" class="{{ $selectClass }}" onchange="this.form.submit()">
                <option value="">همه وضعیت‌ها</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </form>

        <a href="{{ route('partner-operations.create') }}"
           class="inline-flex h-9 items-center rounded-lg bg-brand-500 px-4 text-sm font-medium text-white transition hover:bg-brand-600">
            ثبت عملیات شریک
        </a>
    </div>

    <x-common.component-card title="عملیات ثبت‌شده">
        <x-tables.data-table :headers="$headers" :paginator="$operations">
            @forelse ($operations as $operation)
                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                    <td class="whitespace-nowrap px-5 py-3 text-xs text-gray-500 sm:px-6 dark:text-gray-400">
                        {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDate($operation->operation_date) }}
                    </td>
                    <td class="px-5 py-3 sm:px-6">
                        <a href="{{ route('partner-operations.show', $operation) }}" class="font-medium text-brand-500 hover:underline">
                            {{ $operation->party->name }}
                        </a>
                    </td>
                    <td class="whitespace-nowrap px-5 py-3 text-gray-700 sm:px-6 dark:text-gray-300">{{ $operation->type->label() }}</td>
                    <x-tables.num class="px-5 py-3 sm:px-6" :value="$operation->amount" type="toman" />
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-400">{{ $operation->bankAccount?->name ?? '—' }}</td>
                    <td class="px-5 py-3 sm:px-6">
                        <x-ui.status :status="$operation->operationStatus()->badgeStatus()" :label="$operation->operationStatus()->label()" />
                    </td>
                    <td class="px-5 py-3 text-gray-600 sm:px-6 dark:text-gray-400">{{ $operation->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="px-5 py-10">
                        <x-states.state variant="empty"
                            title="هنوز عملیاتی برای شرکا ثبت نشده است"
                            message="آورده، برداشت، توزیع سود و وام شرکا از این‌جا ثبت می‌شوند." />
                    </td>
                </tr>
            @endforelse
        </x-tables.data-table>
    </x-common.component-card>
</div>
@endsection
