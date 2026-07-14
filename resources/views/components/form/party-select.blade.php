@props([
    'name' => 'party_id',
    'label' => 'طرف حساب',
    'value' => null,          // currently selected party id
    'selectedName' => null,   // its name, so the field renders filled on a validation bounce
    'role' => null,           // limit to one business role: customer|supplier|employee|partner
    'placeholder' => 'جستجوی نام، شماره تماس، کد ملی…',
    'required' => false,
    'help' => null,
])

@php
    // uniqid() can start with a digit, which is not a valid CSS id.
    $uid = 'ps-'.uniqid();
    $error = $errors->first($name);
@endphp

{{--
    «طرف حساب» — the ONE party picker.

    It replaces the `<select>` that every financial form used to build for itself:
    a plain dropdown of the first 300–500 parties, ordered by name. With ~1,100
    parties that is not a picker, it is a lottery — the party you needed was
    routinely not in the list at all, and no error said so; the form just offered
    you a different person.

    So the search runs on the SERVER (parties.search), over the whole table, and
    pages. This is the one deliberate exception to the "no fetch, full page
    reloads" rule (CLAUDE.md): the form still submits as a normal POST with a
    plain hidden input — the fetch only finds the id, it never holds form state,
    and with JS off the field degrades to a visible, typed party id rather than
    breaking the page.

    Merged parties are excluded by the endpoint: a duplicate that has been merged
    away must never be selectable again.
--}}
<div x-data="partySelect({
        endpoint: '{{ route('parties.search') }}',
        role: @js($role),
        selected: @js($value ? ['id' => (int) $value, 'name' => $selectedName] : null),
    })"
    x-on:click.outside="close()"
    x-on:keydown.escape="close()"
    class="relative"
    {{ $attributes }}
>
    @if ($label)
        <label for="{{ $uid }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
            {{ $label }}@if ($required)<span class="text-error-500">*</span>@endif
        </label>
    @endif

    {{-- The only thing that is ever submitted. --}}
    <input type="hidden" name="{{ $name }}" x-model="selectedId" @if($required) required @endif>

    <div class="relative">
        <input
            id="{{ $uid }}"
            type="text"
            autocomplete="off"
            x-model="term"
            x-on:input.debounce.250ms="search()"
            x-on:focus="open()"
            :placeholder="selectedName || @js($placeholder)"
            @class([
                'h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 pe-10 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
                'border-gray-300 focus:border-brand-300 dark:border-gray-700 dark:focus:border-brand-800' => ! $error,
                'border-error-500 focus:border-error-300 focus:ring-error-500/20' => $error,
            ])
        >

        {{-- Clear, only once something is chosen. --}}
        <button type="button" x-show="selectedId" x-cloak x-on:click="clear()"
            class="absolute end-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-error-500" aria-label="حذف انتخاب">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
        </button>

        <span x-show="loading" x-cloak class="absolute end-9 top-1/2 -translate-y-1/2 text-gray-400">
            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
        </span>
    </div>

    {{-- The chosen party, stated plainly. A picker that shows only a raw id is a picker you cannot check. --}}
    <p x-show="selectedId" x-cloak class="mt-1.5 text-theme-xs text-gray-600 dark:text-gray-400">
        انتخاب‌شده: <span class="font-medium text-gray-800 dark:text-white/90" x-text="selectedName"></span>
    </p>

    @if ($help)
        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
    @if ($error)
        <p class="mt-1.5 text-theme-xs text-error-500">{{ $error }}</p>
    @endif

    <div x-show="isOpen" x-cloak x-transition.opacity.duration.150ms
        class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">

        <template x-for="party in results" :key="party.id">
            <button type="button" x-on:click="choose(party)"
                class="flex w-full items-center justify-between gap-3 px-4 py-2 text-start hover:bg-gray-50 dark:hover:bg-white/5">
                <span>
                    <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="party.name"></span>
                    <span class="block text-theme-xs text-gray-500 dark:text-gray-400" dir="ltr" x-text="party.phone || ''"></span>
                </span>
                <span class="flex shrink-0 flex-wrap gap-1">
                    <template x-for="role in party.roles" :key="role">
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs text-gray-600 dark:bg-white/5 dark:text-gray-400" x-text="role"></span>
                    </template>
                </span>
            </button>
        </template>

        <p x-show="! loading && results.length === 0" class="px-4 py-6 text-center text-theme-sm text-gray-500 dark:text-gray-400">
            طرف حسابی پیدا نشد.
        </p>

        {{-- Server-side pagination: the next page is fetched, never the whole table. --}}
        <button type="button" x-show="hasMore" x-on:click="more()"
            class="w-full px-4 py-2 text-center text-theme-sm font-medium text-brand-500 hover:bg-gray-50 dark:hover:bg-white/5">
            نمایش موارد بیشتر
        </button>
    </div>
</div>
