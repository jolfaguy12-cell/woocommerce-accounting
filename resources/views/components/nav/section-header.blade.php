@props([
    'title',
    'desc' => null,
    'status' => null,
    'level' => 'section',   // page | section
])

{{--
    Report / dashboard / section header. Title + optional description + status,
    with an `actions` slot on the opposite side (filters, export, finalize…).

    `level` only changes the heading size/weight — the heading LEVEL stays
    sequential (h2) so the document outline is not broken (WCAG heading-hierarchy;
    the page <h1> is supplied by the breadcrumb component).
--}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-start justify-between gap-3']) }}>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h2 @class([
                'font-semibold text-gray-800 dark:text-white/90',
                'text-title-sm' => $level === 'page',
                'text-lg' => $level !== 'page',
            ])>{{ $title }}</h2>

            @if ($status)
                <x-ui.status :status="$status" />
            @endif
        </div>

        @if ($desc)
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">{{ $desc }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
