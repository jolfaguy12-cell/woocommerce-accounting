@props(['pageTitle' => 'Page', 'parentLabel' => null, 'parentUrl' => null])

@php
    $crumbArrow = '<svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" /></svg>';
@endphp

<div class="mb-6">
    <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
        {{ $pageTitle }}
    </h2>
    <nav class="mt-1.5">
        <ol class="flex flex-wrap items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500">
            <li>
                <a
                    class="inline-flex items-center gap-1.5 hover:text-gray-600 dark:hover:text-gray-300"
                    href="{{ url('/') }}"
                >
                    Home
                    <span class="rotate-180">{!! $crumbArrow !!}</span>
                </a>
            </li>
            @if ($parentLabel)
                <li>
                    <a
                        class="inline-flex items-center gap-1.5 hover:text-gray-600 dark:hover:text-gray-300"
                        href="{{ $parentUrl }}"
                    >
                        {{ $parentLabel }}
                        <span class="rotate-180">{!! $crumbArrow !!}</span>
                    </a>
                </li>
            @endif
            <li class="text-gray-500 dark:text-gray-400">
                {{ $pageTitle }}
            </li>
        </ol>
    </nav>
</div>
