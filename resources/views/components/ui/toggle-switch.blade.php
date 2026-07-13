@props([
    'name' => 'value',
    'checked' => false,
    'disabled' => false,
])

{{--
    Instant on/off action, submitted as a plain POST — no fetch/AJAX (see
    CLAUDE.md). Must sit inside a <form> (with @csrf and the target action);
    flipping it requests that form's submit immediately (requestSubmit(), so
    the form's own onsubmit — e.g. a confirm() guard — still runs, unlike the
    onchange="this.form.submit()" filter selects elsewhere in this app).

    The hidden input before the checkbox is the standard Laravel/Rails
    boolean-checkbox trick: an unchecked checkbox sends nothing at all, so
    without it the server could never tell "unchecked" apart from "field not
    present." Server always receives 0 or 1 either way.
--}}
<label {{ $attributes->class([
    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
    'cursor-not-allowed opacity-50' => $disabled,
    'cursor-pointer' => ! $disabled,
]) }}>
    <input type="hidden" name="{{ $name }}" value="0">
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @checked($checked)
        @disabled($disabled)
        onchange="this.form.requestSubmit()"
        class="peer sr-only"
    >
    <span class="pointer-events-none absolute inset-0 rounded-full bg-gray-200 transition-colors peer-checked:bg-brand-500 dark:bg-gray-700"></span>
    <span class="pointer-events-none absolute right-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:-translate-x-5"></span>
</label>
