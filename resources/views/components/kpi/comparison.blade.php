@props([
    'label',
    'current' => null,
    'previous' => null,
    'currentLabel' => 'این دوره',
    'previousLabel' => 'دوره قبل',
    'type' => 'toman',
    'status' => null,
])

{{--
    Two periods side by side with the delta computed and labelled. The change is
    derived here purely for DISPLAY (it is a presentation of the two numbers the
    caller already supplied) — no business logic, no queries.

    The delta is shown as an arrow + explicit signed percentage + a text label,
    so it never depends on colour alone (WCAG color-not-only).
--}}
@php
    $hasBoth = $current !== null && $previous !== null && (float) $previous != 0.0;
    $change = $hasBoth ? (((float) $current - (float) $previous) / abs((float) $previous)) * 100 : null;
    $dir = $change === null ? 'flat' : \App\Support\Design\StatusPresenter::trend($change);

    $trendClass = match ($dir) {
        'up' => 'text-trend-up',
        'down' => 'text-trend-down',
        default => 'text-trend-flat',
    };
    $arrow = match ($dir) { 'up' => '▲', 'down' => '▼', default => '—' };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white p-5 shadow-card dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-start justify-between gap-3">
        <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
        @if ($status)
            <x-ui.status :status="$status" />
        @endif
    </div>

    <div class="mt-4 flex items-end gap-4">
        <div>
            <p class="text-caption text-gray-400">{{ $currentLabel }}</p>
            <p class="text-kpi font-semibold text-gray-800 dark:text-white/90">
                <x-tables.num :value="$current" :type="$type" :cell="false" />
            </p>
        </div>

        <div class="pb-1">
            <p class="text-caption text-gray-400">{{ $previousLabel }}</p>
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                <x-tables.num :value="$previous" :type="$type" :cell="false" />
            </p>
        </div>
    </div>

    @if ($change !== null)
        <p class="mt-3 flex items-center gap-1.5 text-theme-xs">
            <span class="{{ $trendClass }} font-medium" dir="ltr">
                <span aria-hidden="true">{{ $arrow }}</span>
                {{ ($change > 0 ? '+' : '').number_format($change, 1) }}٪
            </span>
            <span class="text-gray-400">نسبت به {{ $previousLabel }}</span>
        </p>
    @endif
</div>
