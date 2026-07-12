@props(['categories' => null, 'series' => null])

<div
    class="overflow-hidden rounded-card border border-gray-200 bg-white px-5 pt-5 sm:px-6 sm:pt-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
            تعداد سفارشات ماهانه
        </h3>

        <!-- Dropdown Menu -->
        <x-common.dropdown-menu />
        <!-- End Dropdown Menu -->
    </div>

    {{-- Migrated off the #chartOne + chart-1.js pair onto the shared chart system:
         the `bar` preset owns all styling, this widget only supplies data. --}}
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <x-charts.chart
            preset="bar"
            height="md"
            :series="$series ?? []"
            :categories="$categories ?? []"
            class="min-w-[690px] xl:min-w-full"
            emptyMessage="سفارشی در این دوره ثبت نشده است" />
    </div>
</div>
