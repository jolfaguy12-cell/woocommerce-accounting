@props([
    'value' => null,
    'placeholder' => '—',
    'mono' => false,   // true → tabular figures (IBAN, card number, reference codes)
    'cell' => true,
    'tone' => 'default', // default | muted | subtle | brand — mirrors the num primitive
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

    Colour is owned here for the same reason it is in <x-tables.num>: inheriting
    it from the caller's <td> meant an identifier with no colour class fell back
    to the browser default (pure black) — illegible in dark mode. Pick a `tone`;
    do not pass a raw text-* colour class.
--}}
@php
    $isEmpty = $value === null || $value === '';

    // Tones invert with the background so they clear WCAG AA at both ends —
    // brand-500 is only 3.1:1 on the dark surface, hence brand-400 there.
    $toneClass = $isEmpty
        ? 'text-gray-500 dark:text-gray-400'
        : match ($tone) {
            'muted' => 'text-gray-600 dark:text-gray-300',
            'subtle' => 'text-gray-500 dark:text-gray-400',
            'brand' => 'text-brand-500 dark:text-brand-400',
            default => 'text-gray-800 dark:text-white/90',
        };

    $class = 'whitespace-nowrap '.$toneClass.($mono ? ' tabular-fig' : '');
@endphp

@if ($cell)
    <td {{ $attributes->merge(['class' => 'px-5 py-3 text-right sm:px-6']) }} dir="ltr">
        <span class="{{ $class }}">{{ $isEmpty ? $placeholder : $value }}</span>
    </td>
@else
    <span {{ $attributes->merge(['class' => 'text-right '.$class]) }} dir="ltr">{{ $isEmpty ? $placeholder : $value }}</span>
@endif
