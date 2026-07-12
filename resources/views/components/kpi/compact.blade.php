@props([
    'label',
    'value' => null,
    'type' => 'int',
    'unit' => null,
    'change' => null,
    'status' => null,
    'icon' => null,
])

{{--
    Dense KPI for strips and sidebars — same information hierarchy as
    <x-kpi.card> (label above, figure dominant, trend beneath) but without the
    card chrome, so it can sit inside another surface. Used by
    <x-widgets.quick-stats>.
--}}
@php
    $dir = $change === null ? null : \App\Support\Design\StatusPresenter::trend($change);
    $trendClass = match ($dir) {
        'up' => 'text-trend-up',
        'down' => 'text-trend-down',
        default => 'text-trend-flat',
    };
    $arrow = match ($dir) { 'up' => '▲', 'down' => '▼', default => '—' };
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <div class="flex items-center gap-2">
        @if ($icon)
            <span class="shrink-0 text-gray-400">{!! $icon !!}</span>
        @endif
        <span class="truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $label }}</span>
        @if ($status)
            <x-ui.status :status="$status" />
        @endif
    </div>

    <p class="mt-1.5 text-theme-xl font-semibold text-gray-800 dark:text-white/90">
        <x-tables.num :value="$value" :type="$type" :unit="$unit" :cell="false" />
    </p>

    @if ($change !== null)
        <p class="mt-0.5 text-caption {{ $trendClass }}" dir="ltr">
            <span aria-hidden="true">{{ $arrow }}</span>
            {{ ($change > 0 ? '+' : '').number_format((float) $change, 1) }}٪
        </p>
    @endif
</div>
