@props([
    'label',                    // e.g. «فروش ناخالص»
    'value' => null,            // int|float|string|null  (null → shows placeholder)
    'unit' => null,             // e.g. «تومان»
    'change' => null,           // signed % vs the comparison period, e.g. -3.2
    'comparisonLabel' => 'نسبت به ماه قبل',
    'icon' => null,             // raw SVG string
    'status' => null,           // renders a status badge in the corner
                                // NB: never write a component tag inside a // comment —
                                // Blade's tag compiler scans raw text and would compile it.
    'variant' => 'statistic',   // statistic | financial | goal | warning | insight
    'progress' => null,         // 0..100 → goal variant progress bar
    'sparkline' => null,        // array of numbers → kpi-trend chart
    'placeholder' => '—',
])

{{--
    The KPI primitive for every dashboard/report. Composes the design-system
    tokens rather than re-declaring colours:
      • value      → <x-tables.num cell=false> (tabular figures, dir=ltr, right)
      • change     → trend tokens (--color-trend-up/down/flat)
      • sparkline  → the shared `kpi-trend` chart preset (no element id needed)

    Accessibility: the trend is conveyed by an ARROW + SIGNED NUMBER + a text
    comparison label, never by colour alone (WCAG color-not-only).
--}}
@php
    $hasChange = $change !== null;
    $dir = $hasChange ? \App\Support\Design\StatusPresenter::trend($change) : 'flat';

    $trendClass = match ($dir) {
        'up' => 'text-trend-up',
        'down' => 'text-trend-down',
        default => 'text-trend-flat',
    };
    $trendArrow = match ($dir) {
        'up' => '▲',
        'down' => '▼',
        default => '—',
    };

    $accent = match ($variant) {
        'financial' => 'text-profit',
        'warning' => 'text-expense',
        default => 'text-gray-800 dark:text-white/90',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white p-5 shadow-card dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            @if ($icon)
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    {!! $icon !!}
                </span>
            @endif
            <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
        </div>

        @if ($status)
            <x-ui.status :status="$status" />
        @endif
    </div>

    <div class="mt-4 flex items-end justify-between gap-3">
        <div>
            <p class="text-kpi font-semibold {{ $accent }}">
                @if ($value === null)
                    {{ $placeholder }}
                @else
                    <x-tables.num :value="$value" :unit="$unit" :cell="false" />
                @endif
            </p>

            @if ($hasChange)
                <p class="mt-1 flex items-center gap-1.5 text-theme-xs">
                    <span class="{{ $trendClass }} font-medium" dir="ltr">
                        <span aria-hidden="true">{{ $trendArrow }}</span>
                        {{ ($change > 0 ? '+' : '').number_format((float) $change, 1) }}٪
                    </span>
                    <span class="text-gray-400">{{ $comparisonLabel }}</span>
                </p>
            @endif
        </div>

        @if ($sparkline)
            {{-- Size the WRAPPER, not the chart: <x-charts.chart> defaults to w-full,
                 and a width class passed straight to it would collide with that. --}}
            <div class="w-24 shrink-0">
                <x-charts.chart preset="kpi-trend" height="xs" :series="$sparkline" />
            </div>
        @endif
    </div>

    @if ($progress !== null)
        <div class="mt-4">
            <div class="h-2 w-full overflow-hidden rounded-badge bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-badge bg-brand-500" style="width: {{ max(0, min(100, (float) $progress)) }}%"></div>
            </div>
            <p class="mt-1.5 text-caption text-gray-400" dir="ltr">{{ number_format((float) $progress, 1) }}٪</p>
        </div>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">{{ $slot }}</div>
    @endif
</div>
