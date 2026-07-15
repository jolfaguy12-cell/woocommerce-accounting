@props([
    // A raw Alpine JS expression (NOT a Blade value) evaluating to the field
    // name prefix for this row, e.g. a template literal like lines-idx-here.
    // Interpolated unescaped on purpose — see the raw echo below.
    'namePrefix' => "'line'",
])

{{--
    One purchase-invoice line's product picker: search box + results
    dropdown, shared between the create form's lines and the edit form's new
    lines (the two places CLAUDE.md's "one approved AJAX exception" — the
    product combobox — appears). Must be rendered textually INSIDE an
    `x-for="(line, idx) in lines"` template: `line` (a window.makePurchaseLine()
    object — see app.js) and `searchEndpoint` are read from that enclosing
    Alpine scope, the same technique <x-form.party-select> uses for its own
    dropdown. This never becomes a client-side data store: the fetch only
    finds a product id, and the id still submits as a plain hidden input.
--}}
<div class="relative col-span-6">
    <input type="text" x-model="line.product_name"
        x-on:input="line.search(searchEndpoint, $event.target.value)"
        x-on:focus="line.results.length > 0 && (line.open = true)"
        placeholder="نام کالا یا SKU را برای جستجو تایپ کنید…" autocomplete="off" dir="rtl"
        class="h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30">
    <input type="hidden" :name="{!! $namePrefix !!} + '[product_mirror_id]'" :value="line.product_mirror_id">

    <span x-show="line.loading" x-cloak class="absolute end-3 top-1/2 -translate-y-1/2 text-gray-400">
        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
    </span>

    <div x-show="line.open && line.results.length > 0" x-cloak x-on:click.outside="line.open = false"
        class="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
        <template x-for="item in line.results" :key="item.id">
            <button type="button" x-on:click="line.pick(item)"
                class="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-white/5"
                :class="line.product_mirror_id === item.id && 'bg-brand-50 dark:bg-brand-500/10'">
                <span x-data="{ broken: false }" class="relative h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                    <img x-show="item.thumbnail_url && !broken" :src="item.thumbnail_url" x-on:error="broken = true"
                        loading="lazy" class="h-full w-full object-cover">
                    <span x-show="!item.thumbnail_url || broken" x-cloak class="absolute inset-0 flex items-center justify-center text-gray-300 dark:text-white/20">
                        @include('components.media.partials.no-image-icon', ['iconSizeClass' => 'size-4'])
                    </span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.name"></span>
                    <span class="block text-theme-xs text-gray-500 dark:text-gray-400" dir="ltr" x-text="item.sku ? 'SKU: ' + item.sku : ''"></span>
                </span>
                <span class="shrink-0 text-theme-xs font-medium"
                    :class="item.stock_quantity > 0 ? 'text-success-600 dark:text-success-400' : 'text-error-500 dark:text-error-400'"
                    x-text="item.stock_quantity > 0 ? ('موجودی: ' + item.stock_quantity) : 'ناموجود'"></span>
            </button>
        </template>
    </div>

    <p x-show="line.open && !line.loading && line.results.length === 0 && line.product_name.length >= 2" x-cloak
        class="mt-1 px-1 text-theme-xs text-gray-500 dark:text-gray-400">
        کالایی پیدا نشد.
    </p>

    <button type="button" x-on:click="line.showNew = !line.showNew; line.product_mirror_id = ''"
        class="mt-1 text-xs text-brand-500 hover:underline" x-text="line.showNew ? 'جستجوی کالای موجود' : 'کالای من در فهرست نیست…'"></button>

    <input x-show="line.showNew" x-cloak :name="{!! $namePrefix !!} + '[new_item_name]'" type="text" placeholder="نام کالای جدید (مثلاً بسته‌بندی)"
        class="mt-1 h-9 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
</div>
