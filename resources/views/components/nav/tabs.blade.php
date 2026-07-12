@props([
    'tabs' => [],        // ['key' => 'label', ...]  or  [['key','label','count','url'], ...]
    'active' => null,
    'param' => null,     // set → URL-driven tabs (server-rendered, shareable)
                         // null → Alpine-local tabs (client-only panels)
    'panels' => false,   // true → render $slot panels keyed by tab
])

{{--
    Tabs, in both flavours the spec asks for:

      • URL-driven  (pass `param`)  → each tab is a LINK carrying the current query
        string with that param swapped. Server-rendered, shareable, back-button
        safe. Use for report sections that change the data.
      • Alpine-local (omit `param`) → pure client state, no navigation. Use for
        cosmetic switches inside one dataset.

    Keyboard: links/buttons are natively focusable and Enter/Space activate them;
    arrow-key roving is intentionally NOT added because these are real links in
    the URL-driven case, where browser tab-order is the expected behaviour.
    `aria-current` / `aria-selected` mark the active tab for screen readers, so
    it is never signalled by colour alone. Overflows horizontally on small
    screens rather than wrapping into an unreadable pile.
--}}
@php
    $items = [];
    foreach ($tabs as $k => $v) {
        $items[] = is_array($v)
            ? $v + ['key' => $v['key'] ?? $k, 'label' => $v['label'] ?? '']
            : ['key' => $k, 'label' => $v];
    }

    $activeKey = $active ?? ($param ? request()->query($param) : null) ?? ($items[0]['key'] ?? null);

    $urlFor = function ($key) use ($param) {
        $q = array_filter(
            array_merge(request()->query(), [$param => $key, 'page' => null]),
            fn ($v) => $v !== null && $v !== '' && $v !== []
        );

        return request()->url().($q ? '?'.http_build_query($q) : '');
    };
@endphp

<div {{ $attributes->only('class') }}
    @if (! $param) x-data="{ tab: @js($activeKey) }" @endif>

    <div class="custom-scrollbar overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist">
        <div class="flex min-w-max items-center gap-1">
            @foreach ($items as $item)
                @php $isActive = (string) $item['key'] === (string) $activeKey; @endphp

                @if ($param)
                    <a href="{{ $item['url'] ?? $urlFor($item['key']) }}" role="tab"
                        @if ($isActive) aria-selected="true" aria-current="true" @else aria-selected="false" @endif
                        @class([
                            'flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-theme-sm font-medium transition',
                            'border-brand-500 text-brand-600 dark:text-brand-400' => $isActive,
                            'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! $isActive,
                        ])>
                        {{ $item['label'] }}
                        @isset($item['count'])
                            <span class="rounded-badge bg-gray-100 px-1.5 py-0.5 text-caption text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $item['count'] }}</span>
                        @endisset
                    </a>
                @else
                    <button type="button" role="tab" @click="tab = @js($item['key'])"
                        :aria-selected="tab === @js($item['key']) ? 'true' : 'false'"
                        :class="tab === @js($item['key'])
                            ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-theme-sm font-medium transition">
                        {{ $item['label'] }}
                        @isset($item['count'])
                            <span class="rounded-badge bg-gray-100 px-1.5 py-0.5 text-caption text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $item['count'] }}</span>
                        @endisset
                    </button>
                @endif
            @endforeach
        </div>
    </div>

    @if ($panels)
        <div class="pt-4">{{ $slot }}</div>
    @endif
</div>
