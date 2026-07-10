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
])

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
        visible: {{ json_encode(array_fill_keys(array_column($columns, 'key'), true)) }},
        columnsOpen: false,
        init() {
            const saved = JSON.parse(localStorage.getItem('{{ $storageKey }}') || '{}');
            Object.assign(this.visible, saved);
        },
        toggle(key) {
            this.visible[key] = !this.visible[key];
            localStorage.setItem('{{ $storageKey }}', JSON.stringify(this.visible));
        },
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

        <div class="relative" @click.away="columnsOpen = false">
            <button type="button" @click="columnsOpen = !columnsOpen" class="h-9 rounded-md border border-gray-300 px-4 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ستون‌ها</button>
            <div x-show="columnsOpen" x-cloak x-transition class="absolute left-0 z-50 mt-1 w-56 rounded-lg border border-gray-200 bg-white p-2 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900">
                @foreach ($columns as $col)
                    <label class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                        <input type="checkbox" :checked="visible['{{ $col['key'] }}']" @change="toggle('{{ $col['key'] }}')">
                        {{ $col['label'] }}
                    </label>
                @endforeach
            </div>
        </div>
    </x-common.filter-bar>

    <x-tables.data-table :headers="$columns" :paginator="$paginator" :emptyMessage="$emptyMessage" :totals="$totals">
        {{ $slot }}
    </x-tables.data-table>
</div>
