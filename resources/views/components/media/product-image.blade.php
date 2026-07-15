@props([
    'src' => null,
    'alt' => '',
    'size' => 'md',       // sm | md | lg
    'rounded' => true,
])

@php
    $sizeClasses = [
        'sm' => 'h-10 w-10',
        'md' => 'h-24 w-24',
        'lg' => 'h-40 w-40 sm:h-56 sm:w-56',
    ];
    $iconSizeClasses = [
        'sm' => 'size-4',
        'md' => 'size-8',
        'lg' => 'size-14',
    ];
    $sizeClass = $sizeClasses[$size] ?? $sizeClasses['md'];
    $iconSizeClass = $iconSizeClasses[$size] ?? $iconSizeClasses['md'];
@endphp

{{--
    The one shared product-image container: loading skeleton, no-image
    fallback (null src — never a network call), and broken-image fallback
    (a URL that 404s or fails to decode). Every screen that shows a product
    picture — the product page, the purchase-line picker's results — renders
    through this so the three states never have to be reimplemented per page.
--}}
@if ($src)
    <div
        x-data="{ loaded: false, broken: false }"
        {{ $attributes->class(["relative shrink-0 overflow-hidden bg-gray-100 dark:bg-white/5 $sizeClass", 'rounded-lg' => $rounded]) }}
    >
        {{-- Skeleton: visible until the image finishes loading. --}}
        <div x-show="!loaded" x-cloak class="absolute inset-0 animate-pulse bg-gray-200 dark:bg-white/10"></div>

        <img
            src="{{ $src }}"
            alt="{{ $alt }}"
            loading="lazy"
            x-show="!broken"
            x-on:load="loaded = true"
            x-on:error="broken = true; loaded = true"
            class="h-full w-full object-cover"
        >

        {{-- Broken-image fallback: the URL 404s or fails to decode. --}}
        <div x-show="broken" x-cloak class="absolute inset-0 flex items-center justify-center text-gray-300 dark:text-white/20">
            @include('components.media.partials.no-image-icon', ['iconSizeClass' => $iconSizeClass])
        </div>
    </div>
@else
    {{-- No Hub image for this product — never a network call, just the fallback. --}}
    <div {{ $attributes->class(["relative shrink-0 flex items-center justify-center overflow-hidden bg-gray-100 text-gray-300 dark:bg-white/5 dark:text-white/20 $sizeClass", 'rounded-lg' => $rounded]) }}>
        @include('components.media.partials.no-image-icon', ['iconSizeClass' => $iconSizeClass])
    </div>
@endif
