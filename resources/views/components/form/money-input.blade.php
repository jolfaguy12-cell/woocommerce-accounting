@props([
    'name' => 'amount',
    'label' => 'مبلغ',
    'value' => null,        // raw integer Toman
    'placeholder' => '۰',
    'required' => false,
    'help' => null,
    'unit' => 'تومان',
])

@php
    $uid = 'mi-'.uniqid();
    $raw = old($name, $value);
    $raw = is_numeric($raw) ? (string) (int) $raw : '';
    $error = $errors->first($name);
@endphp

{{--
    Money in, integer out.

    Every amount in this system is an integer number of Toman. A human typing
    1250000 into a bare text box cannot see whether they typed six zeros or
    seven — and the difference is a factor of ten on a financial record. So the
    field they type in is formatted on every keystroke (1,250,000) and has NO
    name; the field that is submitted is hidden, carries the name, and contains
    nothing but digits. The server keeps its `integer` validation rule unchanged
    and never has to strip a separator.
--}}
<div x-data="moneyInput(@js($raw), @js($name))" {{ $attributes }}>
    @if ($label)
        <label for="{{ $uid }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ $label }}@if ($required)<span class="text-error-500">*</span>@endif
        </label>
    @endif

    <div class="relative">
        <input
            id="{{ $uid }}"
            type="text"
            inputmode="numeric"
            dir="ltr"
            autocomplete="off"
            :value="display"
            x-on:input="onInput($event)"
            placeholder="{{ $placeholder }}"
            @class([
                'h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-right text-sm tabular-nums text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
                'pe-16' => $unit,
                'border-gray-300 focus:border-brand-300 dark:border-gray-700 dark:focus:border-brand-800' => ! $error,
                'border-error-500 focus:border-error-300 focus:ring-error-500/20' => $error,
            ])
        >

        @if ($unit)
            <span class="pointer-events-none absolute end-4 top-1/2 -translate-y-1/2 text-theme-sm text-gray-400">{{ $unit }}</span>
        @endif
    </div>

    {{-- The raw integer. The only thing the server ever sees. --}}
    <input type="hidden" name="{{ $name }}" x-model="raw" @if($required) required @endif>

    @if ($help)
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
    @if ($error)
        <p class="mt-1.5 text-theme-xs text-error-500">{{ $error }}</p>
    @endif
</div>
