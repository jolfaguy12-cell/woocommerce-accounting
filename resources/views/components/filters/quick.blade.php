@props([
    'name' => 'period',   // query param this group drives
    'options' => [],      // ['value' => 'label', ...]
    'active' => null,
    'baseUrl' => null,    // defaults to the current URL (other params preserved)
])

{{--
    Quick filters / period selector / comparison-period selector — a row of
    one-click segments (امروز، این هفته، این ماه، …).

    Each segment is a LINK carrying the full current query string with just this
    param swapped, so a quick filter never drops the active sort or search, and
    the result stays shareable. `aria-current` marks the active one for screen
    readers (not colour alone).
--}}
@php
    $base = $baseUrl ?? request()->url();
    $params = request()->query();

    $urlFor = function ($value) use ($base, $params, $name) {
        $q = array_filter(
            array_merge($params, [$name => $value, 'page' => null]),
            fn ($v) => $v !== null && $v !== '' && $v !== []
        );

        return $base.($q ? '?'.http_build_query($q) : '');
    };
@endphp

<div {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-0.5 rounded-control bg-gray-100 p-0.5 dark:bg-gray-900']) }}
    role="group">
    @foreach ($options as $value => $label)
        @php $isActive = (string) $active === (string) $value; @endphp
        <a href="{{ $urlFor($value) }}"
            @if ($isActive) aria-current="true" @endif
            @class([
                'rounded-md px-3 py-1.5 text-theme-sm font-medium transition',
                'bg-white text-gray-900 shadow-theme-xs dark:bg-gray-800 dark:text-white' => $isActive,
                'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => ! $isActive,
            ])>
            {{ $label }}
        </a>
    @endforeach
</div>
