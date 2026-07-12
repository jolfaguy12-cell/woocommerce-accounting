@props([
    'headers' => [],
    'paginator' => null,
    'emptyMessage' => 'موردی یافت نشد',
    'totals' => null, // optional array of ['label' => ..., 'value' => ...] rendered as a summary footer row
    'stickyHeader' => false,
])

<div class="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="w-full">
            <thead @class(['sticky top-0 z-10 bg-white dark:bg-gray-900' => $stickyHeader])>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    @foreach ($headers as $header)
                        {{-- Headers may be a plain string (always shown) or an array:
                               'label'    (required in array form)
                               'key'      OPTIONAL — opt in to Alpine column-visibility
                                          (needs a `visible` object on an ancestor; see pro-table)
                               'align'    OPTIONAL — 'start' (default) | 'center' | 'end'
                               'sort_url' OPTIONAL (+ 'sort_dir': 'asc'|'desc'|null) → sortable link

                             `align` is deliberately INDEPENDENT of `key`: a column must be
                             alignable without opting into the column-visibility machinery.
                             Numeric/money columns use align => 'end' and <x-tables.num>, which
                             pins header and value to the same edge (see CLAUDE.md → numeric
                             alignment). --}}
                        @php
                            $isArr = is_array($header);
                            $align = $isArr ? ($header['align'] ?? 'start') : 'start';
                            $alignClass = match ($align) {
                                'center' => 'text-center',
                                'end' => 'text-left',   // RTL page: logical "end" of a row is the left edge
                                default => 'text-right',
                            };
                        @endphp
                        <th @if ($isArr && isset($header['key'])) x-show="visible['{{ $header['key'] }}']" @endif
                            class="px-5 py-3 sm:px-6 {{ $alignClass }}">
                            @if ($isArr && isset($header['sort_url']))
                                <a href="{{ $header['sort_url'] }}" class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                    {{ $header['label'] }}
                                    @if (($header['sort_dir'] ?? null) === 'asc')
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15l5-5 5 5"/></svg>
                                    @elseif (($header['sort_dir'] ?? null) === 'desc')
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9l5 5 5-5"/></svg>
                                    @else
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="opacity-40"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg>
                                    @endif
                                </a>
                            @else
                                <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">{{ is_array($header) ? $header['label'] : $header }}</p>
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="[&>tr:hover]:bg-gray-50 dark:[&>tr:hover]:bg-white/[0.03]">
                {{ $slot }}

                @if ($paginator !== null && $paginator->isEmpty())
                    <tr>
                        <td colspan="{{ count($headers) }}" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                            {{ $emptyMessage }}
                        </td>
                    </tr>
                @endif
            </tbody>
            @if ($totals)
                <tfoot>
                    <tr class="border-t border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]">
                        <td colspan="{{ count($headers) }}" class="px-5 py-3 sm:px-6">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                                @foreach ($totals as $total)
                                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                        {{ $total['label'] }}:
                                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $total['value'] }}</span>
                                    </p>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

@if ($paginator !== null && $paginator->hasPages())
    <div class="mt-4">
        {{ $paginator->onEachSide(1)->links('vendor.pagination.custom') }}
    </div>
@endif
