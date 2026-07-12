@props([
    'columns' => [], // [['key' => ..., 'label' => ...], ...] — required for column visibility management
    'paginator' => null,
    'emptyMessage' => 'موردی یافت نشد',
    'totals' => null,
    'searchName' => 'search',
    'searchValue' => null,
    'searchPlaceholder' => 'جستجو',
    'withDateRange' => false,
    'dateFromName' => 'date_from',
    'dateToName' => 'date_to',
    'dateFromValue' => null,
    'dateToValue' => null,
    'clearFiltersRoute' => null,
    'storageKey' => 'proTable.visibleColumns', // localStorage key — pass a page-unique value when using this component more than once per app
    'stickyHeader' => true, // header stays put while the body scrolls
    // --- Phase 3: server-driven state, all via query params (see App\Support\Design\TableQuery) ---
    'query' => null,          // a TableQuery instance → enables chips, page size, no-results
    'filterLabels' => [],     // param name => Persian label, for the chips
])

@php
    // "No results" (filters are on and matched nothing) is a different message
    // from "empty" (there is genuinely no data yet) — the fix for the former is
    // to clear a filter, for the latter it is to add data.
    $hasFilters = $query?->hasActiveFilters() ?? false;
    $chips = $query?->activeFilters($filterLabels) ?? [];

    // A column opts into sorting with 'sort' => '<TableQuery key>'; the header's
    // link and arrow are derived from the query itself. Pages used to build these
    // URLs by hand, which is how sorting and filtering drifted apart — a hand-made
    // sort link that forgot a filter param silently dropped it on click.
    $columns = collect($columns)->map(function (array $col) use ($query) {
        if ($query === null || ! isset($col['sort'])) {
            return $col;
        }

        return $col + [
            'sort_url' => $query->sortUrl($col['sort']),
            'sort_append_url' => $query->sortUrl($col['sort'], append: true),
            'sort_dir' => $query->sortDir($col['sort']),
            'sort_priority' => $query->sortPriority($col['sort']),
        ];
    })->all();
@endphp

{{--
    Reusable list-page shell: search + arbitrary filter controls (via the
    `filters` slot) + optional Jalali date range + a data-table with an
    optional totals footer + pagination + per-column visibility toggling
    (persisted to localStorage). Composes the same primitives pages like
    orders/index already use by hand (x-common.filter-bar, x-tables.data-table,
    x-form.jalali-date-range), plus the orders page's column-toggle pattern,
    so new list pages don't have to re-assemble that boilerplate every time.

    Row markup passed via the default slot should gate each <td> with
    `x-show="visible.<key>"` to match the header — the Alpine state defined
    here is available to slot content since it's all one DOM tree.
--}}
<div
    class="space-y-4"
    x-data="{
        defaults: {{ json_encode(array_fill_keys(array_column($columns, 'key'), true)) }},
        visible: {{ json_encode(array_fill_keys(array_column($columns, 'key'), true)) }},
        columnsOpen: false,
        densityOpen: false,
        density: 'default',
        selected: [],   // row ids; rows bind their checkbox to this
        init() {
            const saved = JSON.parse(localStorage.getItem('{{ $storageKey }}') || '{}');
            Object.assign(this.visible, saved);
            this.density = localStorage.getItem('{{ $storageKey }}.density') || 'default';
        },
        toggle(key) {
            this.visible[key] = !this.visible[key];
            localStorage.setItem('{{ $storageKey }}', JSON.stringify(this.visible));
        },
        resetColumns() {
            this.visible = { ...this.defaults };
            localStorage.removeItem('{{ $storageKey }}');
        },
        setDensity(d) {
            this.density = d;
            this.densityOpen = false;
            localStorage.setItem('{{ $storageKey }}.density', d);
        },
        densityClass() {
            return {
                compact: 'pro-table-compact',
                comfortable: 'pro-table-comfortable',
            }[this.density] ?? '';
        },
        // Header checkbox: checked when all on-page rows are selected,
        // indeterminate when only some are.
        allSelected(ids) { return ids.length > 0 && ids.every(id => this.selected.includes(id)); },
        someSelected(ids) { return this.selected.length > 0 && !this.allSelected(ids); },
        toggleAll(ids) { this.selected = this.allSelected(ids) ? [] : [...ids]; },
    }"
    x-init="init()"
>
    <x-common.filter-bar>
        @if ($searchName)
            <div class="relative">
                <input
                    type="text"
                    name="{{ $searchName }}"
                    value="{{ $searchValue }}"
                    placeholder="{{ $searchPlaceholder }}"
                    class="h-9 w-56 rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90"
                >
            </div>
        @endif

        @if ($withDateRange)
            <x-form.jalali-date-range
                :from-name="$dateFromName"
                :to-name="$dateToName"
                :from-value="$dateFromValue"
                :to-value="$dateToValue"
            />
        @endif

        {{ $filters ?? '' }}

        <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">اعمال فیلتر</button>
        @if ($clearFiltersRoute)
            <a href="{{ $clearFiltersRoute }}" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-600 leading-9 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">پاک کردن فیلترها</a>
        @endif

        {{-- Density: cosmetic, per-user, persisted alongside the column prefs. --}}
        <div class="relative" @click.away="densityOpen = false">
            <button type="button" @click="densityOpen = !densityOpen"
                class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                تراکم
            </button>
            <div x-show="densityOpen" x-cloak x-transition
                class="absolute left-0 z-50 mt-1 w-40 rounded-lg border border-gray-200 bg-white p-2 shadow-dropdown dark:border-gray-800 dark:bg-gray-900">
                @foreach (['compact' => 'فشرده', 'default' => 'پیش‌فرض', 'comfortable' => 'راحت'] as $key => $label)
                    <button type="button" @click="setDensity('{{ $key }}')"
                        :class="density === '{{ $key }}' ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' : 'text-gray-700 dark:text-gray-300'"
                        class="block w-full rounded-md px-2 py-1.5 text-right text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="relative" @click.away="columnsOpen = false">
            <button type="button" @click="columnsOpen = !columnsOpen" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ستون‌ها</button>
            <div x-show="columnsOpen" x-cloak x-transition class="absolute left-0 z-50 mt-1 w-56 rounded-lg border border-gray-200 bg-white p-2 shadow-dropdown dark:border-gray-800 dark:bg-gray-900">
                @foreach ($columns as $col)
                    <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                        <input type="checkbox" :checked="visible['{{ $col['key'] }}']" @change="toggle('{{ $col['key'] }}')">
                        {{ $col['label'] }}
                    </label>
                @endforeach
                <button type="button" @click="resetColumns()"
                    class="mt-1 block w-full rounded-md border-t border-gray-100 px-2 pt-2 text-right text-xs text-gray-500 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400">
                    بازگرداندن پیش‌فرض
                </button>
            </div>
        </div>

        {{-- Export / page-level actions (e.g. an Excel download form). --}}
        {{ $actions ?? '' }}
    </x-common.filter-bar>

    {{-- Bulk-action bar: appears only when rows are selected. Rows opt in by
         rendering a checkbox bound to `selected` (see the showcase example). --}}
    @isset($bulkActions)
        <div x-show="selected.length > 0" x-cloak x-transition
            class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-brand-200 bg-brand-50 px-4 py-2.5 dark:border-brand-800 dark:bg-brand-500/10">
            <p class="text-theme-sm text-brand-700 dark:text-brand-400">
                <span x-text="selected.length"></span> ردیف انتخاب شده
            </p>
            <div class="flex items-center gap-2">
                {{ $bulkActions }}
                <button type="button" @click="selected = []"
                    class="text-theme-xs text-gray-500 hover:text-gray-700 dark:text-gray-400">پاک کردن انتخاب</button>
            </div>
        </div>
    @endisset

    {{-- What is currently narrowing the results, each removable individually. --}}
    @if ($chips)
        <x-filters.chips :filters="$chips" :clearUrl="$query?->clearUrl()" />
    @endif

    <div :class="densityClass()">
        @if ($paginator !== null && $paginator->isEmpty() && $hasFilters)
            {{-- Filters are on and matched nothing: offer to clear them. --}}
            <div class="rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <x-states.state variant="no-results"
                    :action="$query ? ['label' => 'پاک کردن فیلترها', 'url' => $query->clearUrl()] : null" />
            </div>
        @else
            <x-tables.data-table :headers="$columns" :paginator="$paginator" :emptyMessage="$emptyMessage" :totals="$totals" :stickyHeader="$stickyHeader">
                {{ $slot }}
            </x-tables.data-table>
        @endif
    </div>

    {{-- Server-driven pagination + page size; both preserve the active query. --}}
    @if ($paginator !== null && $query !== null)
        <div class="rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <x-nav.pagination :paginator="$paginator" :perPage="$query->perPage()"
                :perPageUrl="fn ($size) => $query->perPageUrl($size)" />
        </div>
    @endif
</div>
