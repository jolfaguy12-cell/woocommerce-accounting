@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="گزارش‌های دوره‌ای" />

<div class="space-y-4" x-data="{ visible: { period: true, state: true, readiness: true, net_profit: true, finalized_at: true } }">
    <x-tables.data-table
        :headers="[
            ['key' => 'period', 'label' => 'دوره'],
            ['key' => 'state', 'label' => 'وضعیت', 'align' => 'center'],
            ['key' => 'readiness', 'label' => 'آمادگی', 'align' => 'center'],
            ['key' => 'net_profit', 'label' => 'سود خالص دوره', 'align' => 'center'],
            ['key' => 'finalized_at', 'label' => 'نهایی‌شده در', 'align' => 'center'],
        ]"
        :paginator="null"
        emptyMessage="هنوز گزارشی ساخته نشده است"
    >
        @foreach ($reports as $row)
            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                <td x-show="visible.period" class="p-3 sm:px-6">
                    <a href="{{ route('reports.show', $row['jalali_period']) }}" class="font-medium text-brand-500 hover:underline" dir="ltr">
                        {{ $row['jalali_period'] }}
                    </a>
                    @if ($row['jalali_period'] === $current_period)
                        <x-ui.badge color="light" size="sm">جاری</x-ui.badge>
                    @endif
                </td>
                <td x-show="visible.state" class="px-5 text-center sm:px-6">
                    <x-reports.state-badge :state="$row['state']" />
                </td>
                <td x-show="visible.readiness" class="px-5 text-center text-sm sm:px-6">
                    @if (in_array($row['state'], ['final', 'adjusted'], true))
                        <span class="text-gray-400 dark:text-gray-500">—</span>
                    @elseif ($row['ready'])
                        <span class="text-success-600 dark:text-success-400">✅</span>
                    @else
                        <span class="text-warning-600 dark:text-orange-400">⚠️ موارد باز</span>
                    @endif
                </td>
                {{-- `signed` supplies the profit/loss colour AND an explicit +/- sign,
                     so the figure never relies on colour alone. --}}
                <x-tables.num x-show="visible.net_profit" class="px-5 sm:px-6" :value="$row['net_period_profit']" :signed="true" />
                <td x-show="visible.finalized_at" class="px-5 text-center text-xs text-gray-500 sm:px-6 dark:text-gray-400">
                    {{ $row['finalized_at'] ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime($row['finalized_at']) : '—' }}
                </td>
            </tr>
        @endforeach
    </x-tables.data-table>

    {{-- Planned purchasing reports (2026-07-10) — not implemented yet, just a punch
         list so the eventual implementation has a ready-made starting point. Data
         source: purchase_invoices + purchase_invoice_lines (already indexed by
         jalali_period and carry qty/unit_price/landed_unit_cost per line), so all
         four are plain aggregate queries once someone builds the UI for them. --}}
    <x-common.component-card title="گزارش‌های خرید کالا (برنامه‌ریزی‌شده — TODO)">
        <ul class="list-inside list-disc space-y-1 text-sm text-gray-500 dark:text-gray-400">
            <li>تعداد کل اقلام خریداری‌شده در ماه جاری (مجموع qty فاکتورهای خرید)</li>
            <li>جمع مبلغ خرید کالا در ماه جاری (qty × unit_price همه اقلام)</li>
            <li>مبلغ کل خرید در ماه جاری (جمع خرید کالا + هزینه‌های ارسال)</li>
            <li>جمع هزینه‌های ارسال کالاهای خریداری‌شده در ماه جاری</li>
            <li>(بعداً) پرفروش‌ترین کالاهای خریداری‌شده این ماه بر اساس مجموع مبلغ سفارش</li>
        </ul>
    </x-common.component-card>
</div>
@endsection
