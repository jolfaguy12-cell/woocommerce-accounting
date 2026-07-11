@props(['categories' => null, 'series' => null])

<div
    class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 sm:px-6 sm:pt-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
            تعداد سفارشات ماهانه
        </h3>

        <!-- Dropdown Menu -->
        <x-common.dropdown-menu />
        <!-- End Dropdown Menu -->
    </div>

    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <div
            id="chartOne"
            class="-mr-5 h-full min-w-[690px] pr-2 xl:min-w-full"
            @if ($categories !== null) data-categories="{{ json_encode($categories, JSON_UNESCAPED_UNICODE) }}" @endif
            @if ($series !== null) data-series="{{ json_encode($series) }}" @endif
        ></div>
    </div>
</div>
