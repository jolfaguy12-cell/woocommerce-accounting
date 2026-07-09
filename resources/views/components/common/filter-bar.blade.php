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
--}}
@php
    $preserved = collect(request()->query())
        ->except(array_merge($except, ['page']))
        ->filter(fn ($value) => $value !== null && $value !== '');
@endphp

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
    {{ $slot }}

    @foreach ($preserved as $key => $value)
        @if (! is_array($value))
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach
</form>
