@props([
    'title' => 'تحقق هدف فروش',
    'desc' => null,
    'actual' => 0,
    'target' => 0,
    'type' => 'toman',
    'series' => null,       // optional per-period actuals → cumulative-vs-target chart
    'targetSeries' => null,
    'categories' => [],
])

{{--
    Sales-target progress. The radial gauge gives the shape; the figures beneath
    give the exact numbers (a gauge alone is not readable to a screen reader, and
    percentages hide the underlying amounts).

    Optionally renders the cumulative actual-vs-target chart when per-period data
    is supplied — the preset accumulates, so the caller passes plain period figures.
--}}
@php
    $pct = $target > 0 ? min(999, ((float) $actual / (float) $target) * 100) : 0;
    $remaining = max(0, (float) $target - (float) $actual);
    $met = $target > 0 && $actual >= $target;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-start justify-between gap-3 px-5 py-4">
        <div>
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if ($desc)
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
            @endif
        </div>
        <x-ui.status :status="$met ? 'completed' : 'processing'" />
    </div>

    <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
        <x-charts.chart preset="radial" height="sm" :series="[round($pct, 1)]" />

        <div class="mt-4 grid grid-cols-3 gap-3 text-center">
            <div>
                <p class="text-caption text-gray-400">محقق‌شده</p>
                <p class="mt-0.5 text-theme-sm font-semibold text-profit">
                    <x-tables.num :value="$actual" :type="$type" :cell="false" />
                </p>
            </div>
            <div>
                <p class="text-caption text-gray-400">هدف</p>
                <p class="mt-0.5 text-theme-sm font-semibold text-gray-700 dark:text-gray-200">
                    <x-tables.num :value="$target" :type="$type" :cell="false" />
                </p>
            </div>
            <div>
                <p class="text-caption text-gray-400">باقی‌مانده</p>
                <p class="mt-0.5 text-theme-sm font-semibold text-gray-700 dark:text-gray-200">
                    <x-tables.num :value="$remaining" :type="$type" :cell="false" :zero="'—'" />
                </p>
            </div>
        </div>
    </div>

    @if ($series)
        <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            <x-charts.chart preset="cumulative-target" height="sm" :categories="$categories"
                :series="[
                    ['name' => 'محقق‌شده', 'data' => $series],
                    ['name' => 'هدف', 'data' => $targetSeries ?? []],
                ]" />
        </div>
    @endif
</div>
