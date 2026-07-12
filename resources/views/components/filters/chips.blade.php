@props([
    'filters' => [],   // from TableQuery::activeFilters() → [['label','value','url'], ...]
    'clearUrl' => null,
])

{{--
    Shows WHAT is currently narrowing the result set, and lets each one be
    removed individually. Every chip's URL comes from TableQuery, so removing a
    filter preserves the sort, the page size and the other filters.

    Renders nothing when no filter is active — no empty toolbar chrome.
--}}
@if (! empty($filters))
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
        <span class="text-theme-xs text-gray-400">فیلترهای فعال:</span>

        @foreach ($filters as $f)
            <span class="inline-flex items-center gap-1.5 rounded-badge bg-brand-50 py-1 pr-2.5 pl-1 text-theme-xs text-brand-700 dark:bg-brand-500/15 dark:text-brand-400">
                <span class="text-brand-500/70 dark:text-brand-400/70">{{ $f['label'] }}:</span>
                <span class="font-medium">{{ $f['value'] }}</span>
                <a href="{{ $f['url'] }}" aria-label="حذف فیلتر {{ $f['label'] }}"
                    class="flex h-4 w-4 items-center justify-center rounded-full hover:bg-brand-100 dark:hover:bg-brand-500/25">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </a>
            </span>
        @endforeach

        @if ($clearUrl)
            <a href="{{ $clearUrl }}" class="text-theme-xs text-gray-500 underline-offset-2 hover:text-gray-700 hover:underline dark:text-gray-400">
                پاک کردن همه
            </a>
        @endif
    </div>
@endif
