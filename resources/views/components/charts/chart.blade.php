@props([
    'preset' => 'bar',        // see resources/js/tailadmin/charts/presets.js
    'series' => [],           // [] | [1,2,3] | [['name' => ..., 'data' => [...]], ...]
    'categories' => [],       // x-axis labels
    'height' => 'md',         // xs | sm | md | lg  (maps to --height-chart-*)
    'colors' => null,         // optional explicit colour override
    'options' => [],          // optional per-instance ApexCharts option overrides
    'emptyMessage' => 'داده‌ای برای نمایش وجود ندارد',
])

{{--
    THE chart primitive. Replaces the old one-file-per-chart approach where each
    widget hard-coded an element id (#chartOne … #chartThirteen) and app.js
    booted it by that id — which meant a chart could never appear twice on one
    page and every new chart needed a bespoke JS file.

    Here the id is GENERATED per instance and the config travels with the element
    as data-chart JSON. resources/js/tailadmin/charts/index.js scans for
    [data-chart], looks the preset up in the registry and renders it. So the same
    preset can be used any number of times on a page, and adding a chart is a
    Blade call with zero JS.

    Charts also re-render on dark-mode toggle (ApexCharts does not follow the
    .dark class on its own) — handled centrally in the initializer.

    NB: the component defaults to `w-full`. To make a chart narrower (e.g. a KPI
    sparkline), size a WRAPPER div — passing a width class here lands next to
    w-full and loses on stylesheet source order.
--}}
@php
    $chartId = 'chart-'.\Illuminate\Support\Str::random(10);

    $config = [
        'preset' => $preset,
        'series' => $series,
        'categories' => $categories,
        'colors' => $colors,
        'options' => (object) $options,
        'emptyMessage' => $emptyMessage,
    ];
@endphp

<div
    id="{{ $chartId }}"
    data-chart='@json($config)'
    {{ $attributes->merge(['class' => 'w-full']) }}
    style="min-height: var(--height-chart-{{ $height }});"
    role="img"
></div>
