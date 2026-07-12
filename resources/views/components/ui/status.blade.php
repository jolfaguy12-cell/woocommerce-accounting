@props([
    'status',
    'label' => null,  // override the presenter's Persian label if a domain needs its own wording
])

{{--
    Design-system status badge. Resolves through App\Support\Design\StatusPresenter
    so one status can never render two ways across the app.

    Renders a coloured DOT + a TEXT label — status is never conveyed by colour
    alone (WCAG color-not-only). Colours come from the --color-status-* tokens,
    which are redefined under .dark, so this is theme-aware with no dark: classes.
--}}
@php
    $s = \App\Support\Design\StatusPresenter::resolve($status);
    $token = $s['token'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-badge px-2.5 py-0.5 text-theme-xs font-medium']) }}
    style="color: var(--color-status-{{ $token }}); background-color: var(--color-status-{{ $token }}-bg);">
    <span class="h-1.5 w-1.5 shrink-0 rounded-full" style="background-color: currentColor;" aria-hidden="true"></span>
    {{ $label ?? $s['label'] }}
</span>
