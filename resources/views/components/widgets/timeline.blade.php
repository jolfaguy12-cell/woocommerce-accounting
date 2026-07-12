@props([
    'title',
    'desc' => null,
    'items' => [],   // [['title'=>…, 'meta'=>?string, 'time'=>?string(Jalali), 'status'=>?string, 'tone'=>'default|success|warning|error', 'url'=>?string], ...]
    'moreUrl' => null,
    'moreLabel' => 'مشاهده همه',
    'emptyMessage' => 'رویدادی ثبت نشده است',
])

{{--
    ONE component behind recent activity, event timeline and the notifications
    list — all a vertical rail of timestamped events. Dates arrive already
    formatted (Jalali) from the caller: no date logic in views.
--}}
@php
    $toneDot = [
        'success' => 'bg-profit',
        'warning' => 'bg-expense',
        'error' => 'bg-loss',
        'default' => 'bg-brand-500',
    ];
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
            <a href="{{ $moreUrl }}" class="shrink-0 text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">{{ $moreLabel }}</a>
        @endif
    </div>

    @if (empty($items))
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <ol class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
            @foreach ($items as $item)
                <li class="relative flex gap-3 pb-4 last:pb-0">
                    {{-- Rail: drawn on every item except the last. --}}
                    @unless ($loop->last)
                        <span class="absolute right-[5px] top-4 h-full w-px bg-gray-200 dark:bg-gray-800" aria-hidden="true"></span>
                    @endunless

                    <span class="relative z-1 mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $toneDot[$item['tone'] ?? 'default'] ?? $toneDot['default'] }}" aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($item['url'] ?? null)
                                <a href="{{ $item['url'] }}" class="text-theme-sm font-medium text-gray-800 hover:text-brand-500 dark:text-white/90">{{ $item['title'] }}</a>
                            @else
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $item['title'] }}</p>
                            @endif
                            @isset($item['status'])
                                <x-ui.status :status="$item['status']" />
                            @endisset
                        </div>
                        @if ($item['meta'] ?? null)
                            <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $item['meta'] }}</p>
                        @endif
                        @if ($item['time'] ?? null)
                            <p class="mt-0.5 text-caption text-gray-400">{{ $item['time'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
