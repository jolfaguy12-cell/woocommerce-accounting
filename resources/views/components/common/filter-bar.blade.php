@props([
    'action' => request()->url(),
    'except' => [],
])

{{--
    Plain GET form: every filter change is a full page reload that
    re-renders the Blade view server-side (no fetch/AJAX). Any active
    query param not re-declared as a visible input inside the slot is
    preserved via a hidden field, so switching one filter never drops
    the others.

    The hidden fields MUST render before the slot: when a query param is
    also a visible input in the slot (the normal case — the slot's <select
    name="status"> IS the same field the hidden hidden-field would
    duplicate), browsers submit same-named fields in DOM order and PHP's
    query-string parsing keeps the LAST one. Hidden-before-slot means the
    user's live selection always wins instead of being silently
    overwritten by the stale value it was reflecting.
--}}
@php
    $preserved = collect(request()->query())
        ->except(array_merge($except, ['page']))
        ->filter(fn ($value) => $value !== null && $value !== '');
@endphp

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
    {{-- Nested params (per-column search arrives as c[col]=…) are preserved too:
         flattening them keeps `sort`, `per_page` AND `c[...]` alive across a
         filter submit, so no interaction silently discards another. --}}
    @foreach ($preserved as $key => $value)
        @if (is_array($value))
            @foreach ($value as $nestedKey => $nestedValue)
                @if (! is_array($nestedValue))
                    <input type="hidden" name="{{ $key }}[{{ $nestedKey }}]" value="{{ $nestedValue }}">
                @endif
            @endforeach
        @else
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach

    {{ $slot }}
</form>
