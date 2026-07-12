@props([
    'title',
    'desc' => null,
    'items' => [],          // [['label'=>…, 'value'=>…, 'meta'=>?string, 'share'=>?float(0-100), 'status'=>?string, 'url'=>?string], ...]
    'type' => 'toman',
    'showRank' => true,
    'showBar' => true,      // relative bar behind each row
    'moreUrl' => null,
    'moreLabel' => 'مشاهده بیشتر',
    'emptyMessage' => 'داده‌ای برای این دوره وجود ندارد',
])

{{--
    ONE component behind every "top N" widget: top products, top customers, top
    categories, top sales channels, leaderboard. They are the same shape — a
    ranked list of label + figure with a relative bar — so it is one
    parameterised component rather than five copies.

    `share` is passed in, or derived from the largest value, purely for the bar
    width. Figures always go through <x-tables.num>.
--}}
@php
    $max = 0.0;
    foreach ($items as $i) {
        $max = max($max, abs((float) ($i['value'] ?? 0)));
    }
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-start justify-between gap-3 px-5 py-4">
        <div>
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if ($desc)
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
            @endif
        </div>
        @if ($moreUrl)
            <a href="{{ $moreUrl }}" class="shrink-0 text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                {{ $moreLabel }}
            </a>
        @endif
    </div>

    @if (empty($items))
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <ul class="border-t border-gray-100 dark:border-gray-800">
            @foreach ($items as $index => $item)
                @php
                    $val = (float) ($item['value'] ?? 0);
                    $share = $item['share'] ?? ($max > 0 ? (abs($val) / $max) * 100 : 0);
                @endphp
                <li class="relative border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                    @if ($showBar)
                        {{-- Relative bar sits behind the row; aria-hidden because the
                             figure beside it already carries the value. --}}
                        <span class="absolute inset-y-0 right-0 bg-brand-50 dark:bg-brand-500/10"
                            style="width: {{ max(0, min(100, $share)) }}%" aria-hidden="true"></span>
                    @endif

                    <div class="relative flex items-center gap-3 px-5 py-2.5">
                        @if ($showRank)
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-badge bg-gray-100 text-theme-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                {{ $index + 1 }}
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            @if ($item['url'] ?? null)
                                <a href="{{ $item['url'] }}" class="block truncate text-theme-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">{{ $item['label'] }}</a>
                            @else
                                <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $item['label'] }}</p>
                            @endif
                            @if ($item['meta'] ?? null)
                                <p class="truncate text-theme-xs text-gray-400">{{ $item['meta'] }}</p>
                            @endif
                        </div>

                        @isset($item['status'])
                            <x-ui.status :status="$item['status']" />
                        @endisset

                        <x-tables.num :value="$item['value']" :type="$type" :cell="false" class="shrink-0 text-theme-sm font-medium text-gray-700 dark:text-gray-200" />
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
