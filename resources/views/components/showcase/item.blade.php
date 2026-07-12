@props(['meta', 'noun'])

@php
    $num = (int) \Illuminate\Support\Str::afterLast($meta['id'], '-');
    $faNum = strtr((string) $num, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    $ref = '«' . $noun . ' شماره ' . $faNum . '»';
@endphp

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" id="{{ $meta['id'] }}">
    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $meta['name'] }}</h3>
            <span class="rounded-md bg-brand-50 px-2 py-0.5 font-mono text-xs text-brand-600 dark:bg-brand-500/15 dark:text-brand-400" dir="ltr">{{ $meta['id'] }}</span>
            @isset($meta['tech'])
                <span class="font-mono text-xs text-gray-400" dir="ltr">{{ $meta['tech'] }}</span>
            @endisset
            <x-ui.badge color="light" size="sm">{{ $ref }}</x-ui.badge>
        </div>

        {{-- The canonical reference label. Copy this to ask for the component by
             name in a future request, e.g. «table-06 — جدول مالی چندسطحی». --}}
        <p class="mt-2 select-all rounded-control bg-gray-50 px-3 py-1.5 font-mono text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300" dir="ltr">
            {{ $meta['id'] }} — {{ $meta['name'] }}
        </p>

        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $meta['description'] }}</p>

        <dl class="mt-3 grid gap-1 text-xs">
            <div class="flex flex-wrap gap-1">
                <dt class="text-gray-400">کامپوننت:</dt>
                <dd class="font-mono text-gray-700 dark:text-gray-300" dir="ltr">{{ $meta['component'] }}</dd>
            </div>
            <div class="flex flex-wrap gap-1">
                <dt class="text-gray-400">مسیر:</dt>
                <dd class="font-mono text-gray-600 dark:text-gray-400" dir="ltr">{{ $meta['source'] }}</dd>
            </div>
            @isset($meta['usage'])
                <div class="flex flex-wrap gap-1">
                    <dt class="text-gray-400">کاربرد:</dt>
                    <dd class="text-gray-600 dark:text-gray-400">{{ $meta['usage'] }}</dd>
                </div>
            @endisset
            @isset($meta['limitations'])
                <div class="flex flex-wrap gap-1">
                    <dt class="text-gray-400">محدودیت:</dt>
                    <dd class="text-expense">{{ $meta['limitations'] }}</dd>
                </div>
            @endisset
            @if (!empty($meta['variants']))
                <div class="flex flex-wrap items-center gap-1.5 pt-1">
                    <dt class="text-gray-400">گونه‌ها:</dt>
                    @foreach ($meta['variants'] as $variant)
                        <dd><x-ui.badge color="light" size="sm">{{ $variant }}</x-ui.badge></dd>
                    @endforeach
                </div>
            @endif
        </dl>
    </div>

    {{-- min-w-0 + overflow-x-auto: a wide preview (a table demo is often wider than
         a phone) must scroll INSIDE its own panel, never push the page into a
         horizontal scroll. --}}
    <div class="min-w-0 overflow-x-auto bg-gray-50 p-5 dark:bg-white/[0.01]">
        <p class="mb-3 text-xs font-medium text-gray-400">پیش‌نمایش زنده</p>
        {{ $slot }}
    </div>
</div>
