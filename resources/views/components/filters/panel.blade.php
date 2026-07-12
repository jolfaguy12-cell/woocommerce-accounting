@props([
    'action' => null,
    'title' => 'فیلتر پیشرفته',
    'open' => false,       // initial state; persisted per storageKey
    'storageKey' => 'filters.panel',
    'activeCount' => 0,
    'clearUrl' => null,
])

{{--
    Collapsible advanced-filter panel. Plain GET form: every filter is a query
    parameter, so the resulting view is shareable and the back button works.
    Alpine only decides whether the panel is open (cosmetic, remembered locally).

    The `filters` slot receives whatever inputs the page needs (status, channel,
    customer, product, Jalali range …) — the panel does not care what they are.
--}}
<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}
    x-data="{
        open: {{ $open ? 'true' : 'false' }},
        init() {
            const saved = localStorage.getItem('{{ $storageKey }}');
            if (saved !== null) this.open = saved === '1';
        },
        toggle() {
            this.open = !this.open;
            localStorage.setItem('{{ $storageKey }}', this.open ? '1' : '0');
        },
    }">

    <button type="button" @click="toggle()" :aria-expanded="open"
        class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-right">
        <span class="flex items-center gap-2">
            <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $title }}</span>
            @if ($activeCount > 0)
                <span class="rounded-badge bg-brand-500 px-2 py-0.5 text-caption font-medium text-white">{{ $activeCount }}</span>
            @endif
        </span>

        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-show="open" x-cloak x-collapse class="border-t border-gray-100 dark:border-gray-800">
        <form method="GET" @if ($action) action="{{ $action }}" @endif class="p-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {{ $slot }}
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button type="submit" class="h-10 rounded-control bg-brand-500 px-4 text-theme-sm font-medium text-white hover:bg-brand-600">
                    اعمال فیلترها
                </button>
                @if ($clearUrl)
                    <a href="{{ $clearUrl }}" class="h-10 rounded-control border border-gray-300 px-4 text-theme-sm leading-10 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        پاک کردن همه
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>
