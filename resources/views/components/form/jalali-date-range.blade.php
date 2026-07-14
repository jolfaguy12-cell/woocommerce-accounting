@props([
    'fromName' => 'date_from',
    'toName' => 'date_to',
    'fromValue' => null, // Gregorian Y-m-d, e.g. request()->get('date_from')
    'toValue' => null,
])

@php
    use Illuminate\Support\Carbon;
    use Morilog\Jalali\Jalalian;

    // See jalali-date.blade.php: fromFormat() parses its input as a JALALI
    // string, not a Gregorian one — fromCarbon() on a real Carbon instance is
    // the actual conversion.
    $fromDisplay = $fromValue ? Jalalian::fromCarbon(Carbon::parse($fromValue))->format('Y/m/d') : '';
    $toDisplay = $toValue ? Jalalian::fromCarbon(Carbon::parse($toValue))->format('Y/m/d') : '';
    // uniqid() can start with a digit, which is not a valid CSS id selector
    // (breaks the picker's internal querySelector lookup) — prefix a letter.
    $uid = 'jdp-'.uniqid();
@endphp

{{--
    Two independent single-date Jalali pickers rather than one "range" input:
    each writes its Gregorian value into its own hidden field via the
    library's data-jdp-target-value-* attributes, so the rest of the form
    (and the backend) only ever deals with plain Y-m-d strings.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-1']) }}>
    <input
        type="text"
        inputmode="none"
        placeholder="از تاریخ"
        autocomplete="off"
        value="{{ $fromDisplay }}"
        data-jdp
        data-jdp-target-value-input="#{{ $uid }}-from-g"
        data-jdp-target-value-type="gregorian"
        class="h-9 w-28 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
    >
    <input type="hidden" id="{{ $uid }}-from-g" name="{{ $fromName }}" value="{{ $fromValue }}">

    <span class="text-gray-400">—</span>

    <input
        type="text"
        inputmode="none"
        placeholder="تا تاریخ"
        autocomplete="off"
        value="{{ $toDisplay }}"
        data-jdp
        data-jdp-target-value-input="#{{ $uid }}-to-g"
        data-jdp-target-value-type="gregorian"
        class="h-9 w-28 rounded-md border border-gray-300 bg-transparent px-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
    >
    <input type="hidden" id="{{ $uid }}-to-g" name="{{ $toName }}" value="{{ $toValue }}">
</div>
