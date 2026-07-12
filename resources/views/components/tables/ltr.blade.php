@props([
    'value' => null,
    'placeholder' => '—',
    'mono' => false,   // true → tabular figures (IBAN, card number, reference codes)
    'cell' => true,
])

{{--
    Companion to <x-tables.num> for LTR *text* identifiers: email, IBAN, card
    number, SKU, phone, invoice number, reference codes, Jalali dates.

    These hit the exact same bug money columns did: they need dir="ltr" to read
    correctly, but that flips the cell's base direction so `text-align: start`
    resolves to LEFT while the <th> stays right — header and value drift apart.
    So, like <x-tables.num>, direction is decoupled from alignment here once:
    dir="ltr" + text-right, pinned to the same edge the header sits on.

    Use <x-tables.num> for anything you would do arithmetic on; use this for
    identifiers that merely happen to read left-to-right.
--}}
@php
    $isEmpty = $value === null || $value === '';
    $class = 'whitespace-nowrap'.($mono ? ' tabular-fig' : '');
@endphp

@if ($cell)
    <td {{ $attributes->merge(['class' => 'px-5 py-3 text-right sm:px-6']) }} dir="ltr">
        <span class="{{ $class }}">{{ $isEmpty ? $placeholder : $value }}</span>
    </td>
@else
    <span {{ $attributes->merge(['class' => 'text-right '.$class]) }} dir="ltr">{{ $isEmpty ? $placeholder : $value }}</span>
@endif
