@props([
    'title',
    'desc' => null,
    'rows' => [],       // [['label' => ..., 'value' => int|null, 'type' => 'toman', 'signed' => bool, 'status' => ?string, 'muted' => bool], ...]
    'total' => null,    // ['label' => ..., 'value' => ..., 'type' => ..., 'signed' => true]
    'status' => null,   // status badge in the header
    'emptyMessage' => null,
])

{{--
    ONE component behind every "summary"-shaped financial widget: profit summary,
    cash flow, receivables, payables, loans, cheques, tax, monthly closing,
    refunds, lost sales… They are all a titled card of label→number rows with an
    emphasised total, so they are one parameterised component, not fourteen
    copies (CLAUDE.md: no duplicate implementations).

    Numbers go through <x-tables.num> so they inherit tabular figures, the
    profit/loss colouring and the +/- sign — never hand-format money here.
    Data arrives via props; this view holds no business logic and no queries.
--}}
<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    <div class="flex items-start justify-between gap-3 px-5 py-4">
        <div>
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if ($desc)
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">{{ $desc }}</p>
            @endif
        </div>
        @if ($status)
            <x-ui.status :status="$status" />
        @endif
    </div>

    @if (empty($rows))
        <div class="border-t border-gray-100 dark:border-gray-800">
            <x-states.state variant="empty" :message="$emptyMessage" />
        </div>
    @else
        <div class="border-t border-gray-100 dark:border-gray-800">
            <table class="w-full">
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-gray-50 last:border-0 dark:border-gray-800/60">
                            <td class="px-5 py-2.5 text-theme-sm {{ ($row['muted'] ?? false) ? 'text-gray-400' : 'text-gray-600 dark:text-gray-300' }}">
                                <span class="flex items-center gap-2">
                                    {{ $row['label'] }}
                                    @isset($row['status'])
                                        <x-ui.status :status="$row['status']" />
                                    @endisset
                                </span>
                            </td>
                            <x-tables.num
                                :value="$row['value'] ?? null"
                                :type="$row['type'] ?? 'toman'"
                                :signed="$row['signed'] ?? false"
                                class="text-theme-sm" />
                        </tr>
                    @endforeach
                </tbody>

                @if ($total)
                    <tfoot>
                        <tr class="border-t border-gray-200 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.02]">
                            <td class="px-5 py-3 text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                                {{ $total['label'] }}
                            </td>
                            <x-tables.num
                                :value="$total['value'] ?? null"
                                :type="$total['type'] ?? 'toman'"
                                :signed="$total['signed'] ?? true"
                                class="text-base font-semibold" />
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    @if (trim($slot) !== '')
        <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">{{ $slot }}</div>
    @endif
</div>
