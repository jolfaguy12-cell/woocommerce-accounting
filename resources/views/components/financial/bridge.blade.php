@props([
    'title' => 'پل سود ناخالص تا خالص',
    'desc' => null,
    'steps' => [],   // [['label' => 'سود ناخالص', 'value' => 5_000_000], ['label' => 'هزینه‌ها', 'value' => -800_000], ...]
    'type' => 'toman',
    'emptyMessage' => 'داده‌ای برای این دوره ثبت نشده است',
])

{{--
    Gross → net profit bridge (waterfall). Each step is a SIGNED delta; the
    `pnl-bridge` chart preset accumulates them, colours positives with the profit
    token and negatives with the loss token, and closes with a net bar.

    The table underneath is not decoration: a waterfall is hard to read exactly,
    and WCAG requires a text/tabular equivalent for chart data.
--}}
@php
    $labels = array_map(fn ($s) => $s['label'], $steps);
    $values = array_map(fn ($s) => (float) ($s['value'] ?? 0), $steps);
    $net = array_sum($values);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="px-5 py-4">
        <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
        @if ($desc)
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
        @endif
    </div>

    @if (empty($steps))
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            <x-charts.chart preset="pnl-bridge" height="md" :series="$values" :categories="$labels" />
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800">
            <table class="w-full">
                <tbody>
                    @foreach ($steps as $step)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-2.5 text-theme-sm text-gray-600 dark:text-gray-300">{{ $step['label'] }}</td>
                            <x-tables.num :value="$step['value']" :type="$type" :signed="true" class="text-theme-sm" />
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]">
                        <td class="px-5 py-3 text-theme-sm font-semibold text-gray-800 dark:text-white/90">سود خالص</td>
                        <x-tables.num :value="$net" :type="$type" :signed="true" class="text-base font-semibold" />
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
