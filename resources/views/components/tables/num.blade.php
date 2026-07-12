@props([
    'value' => null,
    'type' => 'int',     // int | decimal | money | toman | rial | percent
    'signed' => false,   // colour by sign (profit/loss) AND prefix an explicit +/-
    'unit' => null,      // explicit unit override; money types supply their own
    'decimals' => null,  // null → sensible default per type
    'digits' => 'latin', // latin | fa — the IRANSansXFaNum font already renders
                         // ASCII digits as Persian glyphs, so `latin` is correct
                         // for tables (it keeps figures tabular). Use `fa` only
                         // where real Persian codepoints are required.
    'placeholder' => '—',
    'zero' => null,      // optional text to show instead of a bare 0
    'cell' => true,      // false → render just the span (KPI values, summary rows)
    'tone' => 'default', // default | muted | subtle | positive | negative
])

{{--
    THE numeric primitive. Every money / id / quantity / percentage figure in the
    app goes through this — see CLAUDE.md.

    Why it exists (the Phase 2 alignment fix, which must never regress):
      The page is dir="rtl". Digits, grouping separators and the minus sign must
      read LTR, so numeric cells carry dir="ltr" — but that flips the cell's base
      direction, making the default `text-align: start` resolve to LEFT while the
      table header stays right-aligned. Direction is therefore decoupled from
      alignment HERE, once:

        dir="ltr"    → correct digit / minus-sign rendering
        text-right   → PHYSICAL right edge = the RTL reading start = where <th>
                       already sits, so header and value cannot drift apart
        tabular-fig  → fixed-width figures, so amounts stack by place value

    Colour is owned here too, via `tone` — and for the same reason. `body` sets
    no text colour, so a figure that inherited its colour from the caller's <td>
    fell back to the browser default (pure black) whenever the caller forgot to
    pass one: illegible in dark mode, and heavier than its neighbours in light.
    Callers therefore pick a SEMANTIC tone; they must not pass a raw text-*
    colour class (it would be overridden anyway — the tone sits on the figure
    span, which beats any colour merely inherited from the <td>).

    Colour never carries meaning alone: `signed` adds an explicit +/− sign next
    to the profit/loss colour (WCAG color-not-only), and it outranks `tone`.
--}}
@php
    $isNull = $value === null || $value === '';
    $num = $isNull ? null : (float) $value;

    $defaultDecimals = match ($type) {
        'decimal' => 2,
        'percent' => 1,
        default => 0,   // money/toman/rial/int are whole numbers in this system
    };
    $dp = $decimals ?? $defaultDecimals;

    $defaultUnit = match ($type) {
        'toman', 'money' => 'تومان',
        'rial' => 'ریال',
        'percent' => '٪',
        default => null,
    };
    $shownUnit = $unit ?? $defaultUnit;

    $formatted = null;
    if (! $isNull) {
        if ($zero !== null && (float) $num === 0.0) {
            $formatted = $zero;
        } else {
            $formatted = number_format($num, $dp);
            if ($signed && $num > 0) {
                $formatted = '+'.$formatted;
            }
            if ($digits === 'fa') {
                $formatted = strtr($formatted, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
            }
        }
    }

    $isPlain = $isNull || ($zero !== null && (float) $num === 0.0);

    // Each tone darkens on light and lightens on dark — the pairing has to invert
    // with the background, or it fails contrast at one end. (gray-400/gray-500 the
    // other way round is the worst of both: too pale on white, too dim on black.)
    $toneClass = match ($tone) {
        'muted' => 'text-gray-600 dark:text-gray-300',
        'subtle' => 'text-gray-500 dark:text-gray-400',
        'positive' => 'text-success-700 dark:text-success-400',
        'negative' => 'text-error-600 dark:text-error-400',
        default => 'text-gray-800 dark:text-white/90',
    };

    // An unavailable value is never emphasised, whatever the tone.
    $figClass = 'tabular-fig whitespace-nowrap '.($isNull ? 'text-gray-500 dark:text-gray-400' : $toneClass);

    if ($signed && ! $isPlain) {
        // The sign carries the meaning; profit/loss colour reinforces it and outranks the tone.
        $figClass = 'tabular-fig whitespace-nowrap font-medium '.($num > 0 ? 'text-profit' : ($num < 0 ? 'text-loss' : 'text-gray-500 dark:text-gray-400'));
    }
@endphp

@if ($cell)
    <td {{ $attributes->merge(['class' => 'px-5 py-3 text-right sm:px-6']) }} dir="ltr">
        <span class="{{ $figClass }}">{{ $isNull ? $placeholder : $formatted }}</span>
        @if ($shownUnit && ! $isPlain)
            <span class="text-theme-xs text-gray-400">{{ $shownUnit }}</span>
        @endif
    </td>
@else
    <span {{ $attributes->merge(['class' => 'inline-flex items-baseline gap-1 text-right']) }} dir="ltr">
        <span class="{{ $figClass }}">{{ $isNull ? $placeholder : $formatted }}</span>
        @if ($shownUnit && ! $isPlain)
            <span class="text-theme-xs text-gray-400">{{ $shownUnit }}</span>
        @endif
    </span>
@endif
