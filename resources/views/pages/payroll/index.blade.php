@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="لیست‌های حقوق" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" :message="session('success')" />
    @endif

    <div class="flex justify-end">
        <a href="{{ route('payroll.create') }}"
           class="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
            ثبت حقوق دوره
        </a>
    </div>

    @if ($runs->isEmpty())
        <x-states.state variant="empty"
            title="هنوز حقوقی ثبت نشده است"
            message="با «ثبت حقوق دوره»، حقوق ماه در «مانده حقوق» هر کارمند می‌نشیند. پرداخت آن عملیات جداگانه‌ای است." />
    @else
        <x-common.component-card title="لیست‌های حقوق"
            desc="لیست ثبت‌شده تغییرناپذیر است. اصلاح، فقط با برگشت سند انجام می‌شود.">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr class="text-right text-theme-xs text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3 font-medium">دوره</th>
                            <th class="px-4 py-3 font-medium">تعداد کارمند</th>
                            <th class="px-4 py-3 font-medium">جمع ناخالص</th>
                            <th class="px-4 py-3 font-medium">جمع خالص</th>
                            <th class="px-4 py-3 font-medium">وضعیت</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($runs as $run)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-4 py-3 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ \App\Domain\Accounting\Support\JalaliPeriod::label($run->jalali_period) }}
                                </td>
                                <x-tables.num :value="$run->items->count()" type="int" tone="muted" />
                                <x-tables.num :value="$run->grossTotal()" type="toman" />
                                <x-tables.num :value="$run->netTotal()" type="toman" />
                                <td class="px-4 py-3">
                                    <x-ui.status :status="$run->statusBadge()" :label="$run->statusLabel()" />
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('payroll.show', $run) }}" class="text-theme-sm text-brand-500 hover:underline">مشاهده</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $runs->links() }}</div>
        </x-common.component-card>
    @endif
</div>
@endsection
