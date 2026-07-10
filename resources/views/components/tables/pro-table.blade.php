@props([
    'headers' => [],
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
])

{{--
    Reusable list-page shell: search + arbitrary filter controls (via the
    `filters` slot) + optional Jalali date range + a data-table with an
    optional totals footer + pagination. Composes the same primitives pages
    like orders/index already use by hand (x-common.filter-bar,
    x-tables.data-table, x-form.jalali-date-range) so new list pages don't
    have to re-assemble that boilerplate every time.
--}}
<div class="space-y-4">
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
    </x-common.filter-bar>

    <x-tables.data-table :headers="$headers" :paginator="$paginator" :emptyMessage="$emptyMessage" :totals="$totals">
        {{ $slot }}
    </x-tables.data-table>
</div>
