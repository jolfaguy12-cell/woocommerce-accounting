@props([
    'title',
    'desc' => null,
    'items' => [],      // [['label' => ..., 'value' => int, 'status' => ?string], ...]
    'type' => 'toman',
    'chart' => 'donut', // donut | bar-horizontal
    'emptyMessage' => 'داده‌ای برای این دوره ثبت نشده است',
])

{{--
    Composition breakdown: revenue by channel, expenses by category, etc.
    A chart plus a legend table that carries the exact figures — the chart shows
    the shape, the table gives the numbers (WCAG: never rely on the chart alone,
    always provide the tabular equivalent).

    Shares are computed here for display only (a percentage of the passed-in
    values); no business logic, no queries.
--}}
@php
    $total = array_sum(array_map(fn ($i) => (float) ($i['value'] ?? 0), $items));
    $labels = array_map(fn ($i) => $i['label'], $items);
    $values = array_map(fn ($i) => (float) ($i['value'] ?? 0), $items);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="px-5 py-4">
        <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
        @if ($desc)
            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
        @endif
    </div>

    @if (empty($items) || $total <= 0)
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            <x-charts.chart :preset="$chart" height="sm" :series="$values" :categories="$labels" />
        </div>

        <div class="border-t border-gray-100 dark:border-gray-800">
            <table class="w-full">
                <tbody>
                    @foreach ($items as $item)
                        @php $share = $total > 0 ? ((float) $item['value'] / $total) * 100 : 0; @endphp
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-2.5 text-theme-sm text-gray-600 dark:text-gray-300">
                                <span class="flex items-center gap-2">
                                    {{ $item['label'] }}
                                    @isset($item['status'])
                                        <x-ui.status :status="$item['status']" />
                                    @endisset
                                </span>
                            </td>
                            <x-tables.num :value="$share" type="percent" class="w-20 text-theme-xs" tone="subtle" />
                            <x-tables.num :value="$item['value']" :type="$type" class="text-theme-sm" />
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]">
                        <td class="px-5 py-3 text-theme-sm font-semibold text-gray-800 dark:text-white/90">جمع</td>
                        <td></td>
                        <x-tables.num :value="$total" :type="$type" class="text-base font-semibold" />
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
