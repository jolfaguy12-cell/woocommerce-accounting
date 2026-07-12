@props([
    'paginator',
    'perPage' => null,        // current page size
    'perPageOptions' => [15, 25, 50, 100],
    'perPageUrl' => null,     // fn(int $size): string — from TableQuery::perPageUrl()
])

{{--
    Server-driven pagination. Laravel's paginator already preserves the query
    string when the caller does ->withQueryString(), so sorting/filters survive
    paging. Persian labels, RTL arrows, page-size selector.

    Renders nothing when everything fits on one page AND no page-size choice is
    offered — no empty footer chrome.
--}}
@php
    $showPages = $paginator->hasPages();
@endphp

@if ($showPages || $perPageUrl)
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-3 px-5 py-3']) }}>
        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
            نمایش
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ number_format($paginator->firstItem() ?? 0) }}</span>
            تا
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ number_format($paginator->lastItem() ?? 0) }}</span>
            از
            <span class="font-medium text-gray-700 dark:text-gray-200">{{ number_format($paginator->total()) }}</span>
            مورد
        </p>

        <div class="flex flex-wrap items-center gap-3">
            @if ($perPageUrl)
                <div class="flex items-center gap-1.5">
                    <span class="text-theme-xs text-gray-400">تعداد در صفحه:</span>
                    @foreach ($perPageOptions as $size)
                        <a href="{{ $perPageUrl($size) }}"
                            @if ((int) $perPage === (int) $size) aria-current="true" @endif
                            @class([
                                'rounded-md px-2 py-1 text-theme-xs font-medium transition',
                                'bg-brand-500 text-white' => (int) $perPage === (int) $size,
                                'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' => (int) $perPage !== (int) $size,
                            ])>
                            {{ $size }}
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($showPages)
                <nav class="flex items-center gap-1" aria-label="صفحه‌بندی">
                    {{-- RTL: "previous" points right. --}}
                    @if ($paginator->onFirstPage())
                        <span class="flex h-8 w-8 items-center justify-center rounded-md text-gray-300 dark:text-gray-700" aria-disabled="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="صفحه قبل"
                            class="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    @endif

                    {{-- Laravel's elements() already collapses long ranges with '…'. --}}
                    @foreach ($paginator->links()->elements as $element)
                        @if (is_string($element))
                            <span class="px-1.5 text-theme-xs text-gray-400">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <a href="{{ $url }}"
                                    @if ($page == $paginator->currentPage()) aria-current="page" @endif
                                    @class([
                                        'flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-theme-xs font-medium transition',
                                        'bg-brand-500 text-white' => $page == $paginator->currentPage(),
                                        'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $page != $paginator->currentPage(),
                                    ])>
                                    {{ $page }}
                                </a>
                            @endforeach
                        @endif
                    @endforeach

                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="صفحه بعد"
                            class="flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    @else
                        <span class="flex h-8 w-8 items-center justify-center rounded-md text-gray-300 dark:text-gray-700" aria-disabled="true">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    @endif
                </nav>
            @endif
        </div>
    </div>
@endif
