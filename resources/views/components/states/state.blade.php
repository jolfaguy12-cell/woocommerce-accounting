@props([
    'variant' => 'empty',   // empty | no-results | error | permission | loading | skeleton
                            // | stale | partial | offline
    'title' => null,
    'message' => null,
    'action' => null,       // ['label' => ..., 'url' => ...]
    'rows' => 4,            // skeleton only
])

{{--
    Every data surface (table, chart, list, report section) shows one of these
    instead of a blank area. Variants carry sensible Persian defaults so a caller
    usually just writes <x-states.state variant="no-results" />.

    Icons are inline SVG (never emoji). The loading variant is announced to
    screen readers via aria-live; skeleton reserves layout height to avoid CLS.
--}}
@php
    $defaults = [
        'empty' => ['title' => 'هنوز داده‌ای ثبت نشده', 'message' => 'وقتی داده‌ای اضافه شود، اینجا نمایش داده می‌شود.'],
        'no-results' => ['title' => 'نتیجه‌ای یافت نشد', 'message' => 'فیلترها یا عبارت جستجو را تغییر دهید.'],
        'error' => ['title' => 'خطا در بارگذاری', 'message' => 'دریافت اطلاعات ممکن نشد. دوباره تلاش کنید.'],
        'permission' => ['title' => 'دسترسی ندارید', 'message' => 'برای دیدن این بخش با مدیر سامانه تماس بگیرید.'],
        'loading' => ['title' => 'در حال بارگذاری…', 'message' => null],
        'skeleton' => ['title' => null, 'message' => null],
        // Banner states: shown ABOVE data that is still displayed, not instead of it.
        'stale' => ['title' => 'داده‌های قدیمی', 'message' => 'این اعداد از آخرین همگام‌سازی به‌روز نشده‌اند.'],
        'partial' => ['title' => 'داده ناقص', 'message' => 'بخشی از اطلاعات این دوره هنوز کامل نیست؛ اعداد ممکن است تغییر کنند.'],
        'offline' => ['title' => 'اتصال برقرار نیست', 'message' => 'ارتباط با سرویس مبدأ ممکن نشد؛ آخرین داده موجود نمایش داده می‌شود.'],
    ];

    // These warn about data that is STILL SHOWN, so they render as an inline
    // banner rather than replacing the content with an empty state.
    $isBanner = in_array($variant, ['stale', 'partial', 'offline'], true);

    $d = $defaults[$variant] ?? $defaults['empty'];
    $title = $title ?? $d['title'];
    $message = $message ?? $d['message'];

    $icons = [
        'empty' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z" stroke-linejoin="round"/><path d="M3 7.5 12 12l9-4.5M12 12v9" stroke-linejoin="round"/></svg>',
        'no-results' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5" stroke-linecap="round"/></svg>',
        'error' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01" stroke-linecap="round"/></svg>',
        'permission' => '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
    ];

    $tone = match ($variant) {
        'error' => 'text-loss',
        'permission' => 'text-expense',
        default => 'text-gray-400',
    };
@endphp

@if ($isBanner)
    {{-- Warning banner over data that is still displayed. Icon + text, so the
         warning does not depend on colour alone. --}}
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-control border border-expense/30 bg-expense/5 px-4 py-3']) }}
        role="status">
        <span class="mt-0.5 shrink-0 text-expense">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 9v4M12 17h.01" stroke-linecap="round"/>
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linejoin="round"/>
            </svg>
        </span>
        <div class="min-w-0">
            <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $title }}</p>
            @if ($message)
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $message }}</p>
            @endif
            {{ $slot }}
        </div>
        @if ($action)
            <a href="{{ $action['url'] }}" class="ms-auto shrink-0 text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                {{ $action['label'] }}
            </a>
        @endif
    </div>
@elseif ($variant === 'skeleton')
    {{-- Reserves height so async content does not shift the layout (CLS). --}}
    <div {{ $attributes->merge(['class' => 'space-y-3']) }} aria-hidden="true">
        @for ($i = 0; $i < (int) $rows; $i++)
            <div class="h-4 w-full animate-pulse rounded-control bg-gray-100 dark:bg-white/5"></div>
        @endfor
    </div>
@elseif ($variant === 'loading')
    <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-3 py-12']) }}
        role="status" aria-live="polite">
        <div class="h-8 w-8 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></div>
        <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $title }}</p>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-2 px-6 py-12 text-center']) }}>
        <span class="{{ $tone }}">{!! $icons[$variant] ?? $icons['empty'] !!}</span>

        @if ($title)
            <p class="mt-1 font-medium text-gray-700 dark:text-gray-200">{{ $title }}</p>
        @endif
        @if ($message)
            <p class="max-w-sm text-theme-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
        @endif

        {{ $slot }}

        @if ($action)
            <a href="{{ $action['url'] }}"
                class="mt-3 inline-flex h-10 items-center rounded-control bg-brand-500 px-4 text-theme-sm font-medium text-white transition hover:bg-brand-600">
                {{ $action['label'] }}
            </a>
        @endif
    </div>
@endif
