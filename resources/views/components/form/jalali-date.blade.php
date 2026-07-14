@props([
    'name' => 'date',
    'label' => 'تاریخ',
    'value' => null,          // Gregorian Y-m-d — what the server sends and expects back
    'placeholder' => 'انتخاب تاریخ',
    'required' => false,
    'help' => null,
])

@php
    use Illuminate\Support\Carbon;
    use Morilog\Jalali\Jalalian;

    $raw = old($name, $value);
    // Jalalian::fromFormat() parses its input AS a Jalali-formatted string (the
    // Jalalian equivalent of Carbon::createFromFormat), not a Gregorian one — so
    // handing it a Gregorian "2026-07-14" silently produced the wrong Jalali date
    // instead of converting it. fromCarbon() on a real Gregorian Carbon instance
    // is the actual Gregorian→Jalali conversion (same pattern JalaliPeriod::fmtDate()
    // already uses correctly).
    $display = $raw ? Jalalian::fromCarbon(Carbon::parse(substr((string) $raw, 0, 10)))->format('Y/m/d') : '';
    // uniqid() can start with a digit, which is not a valid CSS id selector —
    // the picker's own querySelector lookup would fail on it.
    $uid = 'jd-'.uniqid();
    $error = $errors->first($name);
@endphp

{{--
    One Jalali date field, the same one everywhere.

    The user reads and types Shamsi («۱۴۰۵/۰۴/۲۴»); the server reads and writes
    Gregorian `Y-m-d`, because that is what the date columns hold. The picker
    writes the Gregorian value into the paired hidden field itself, so no
    controller ever has to parse a Jalali string, and no form has to remember to.

    This is the single-date twin of <x-form.jalali-date-range> — same library,
    same contract, so a date field and a date filter cannot drift apart.
--}}
<div {{ $attributes }}>
    @if ($label)
        <label for="{{ $uid }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ $label }}@if ($required)<span class="text-error-500">*</span>@endif
        </label>
    @endif

    <input
        id="{{ $uid }}"
        type="text"
        inputmode="none"
        autocomplete="off"
        dir="ltr"
        value="{{ $display }}"
        placeholder="{{ $placeholder }}"
        data-jdp
        data-jdp-target-value-input="#{{ $uid }}-g"
        data-jdp-target-value-type="gregorian"
        @class([
            'h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-center text-sm tabular-nums text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
            'border-gray-300 focus:border-brand-300 dark:border-gray-700 dark:focus:border-brand-800' => ! $error,
            'border-error-500 focus:border-error-300 focus:ring-error-500/20' => $error,
        ])
    >

    {{-- The Gregorian value. The only thing submitted. --}}
    <input type="hidden" id="{{ $uid }}-g" name="{{ $name }}" value="{{ $raw }}" @if($required) required @endif>

    @if ($help)
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
    @if ($error)
        <p class="mt-1.5 text-theme-xs text-error-500">{{ $error }}</p>
    @endif
</div>
