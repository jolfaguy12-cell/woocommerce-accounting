@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="هزینه بسته‌بندی" />

<div class="space-y-4">
    <x-common.component-card title="مقادیر پیش‌فرض">
        <form method="POST" action="{{ route('warehouse.packaging-cost.defaults') }}" class="grid gap-4 sm:grid-cols-3">
            @csrf
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">هزینه بسته‌بندی پیش‌فرض (تومان)</label>
                <input type="number" name="default_packaging_cost" min="0" dir="ltr" required value="{{ old('default_packaging_cost', $defaults['default_packaging_cost']) }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <p class="mt-1 text-xs text-gray-400">وقتی وزن بسته به هیچ پله‌ای نرسد استفاده می‌شود.</p>
                @error('default_packaging_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">وزن پیش‌فرض کالای بدون وزن (گرم)</label>
                <input type="number" name="default_product_weight_grams" min="0" dir="ltr" required value="{{ old('default_product_weight_grams', $defaults['default_product_weight_grams']) }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <p class="mt-1 text-xs text-gray-400">برای محصولاتی که در هاب وزن ثبت‌شده ندارند.</p>
                @error('default_product_weight_grams')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">وزن پیش‌فرض خود بسته‌بندی (گرم)</label>
                <input type="number" name="default_packaging_weight_grams" min="0" dir="ltr" required value="{{ old('default_packaging_weight_grams', $defaults['default_packaging_weight_grams']) }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <p class="mt-1 text-xs text-gray-400">وزن جعبه/پاکت که به وزن اقلام اضافه می‌شود.</p>
                @error('default_packaging_weight_grams')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="h-10 rounded-lg bg-brand-500 px-5 text-sm font-medium text-white hover:bg-brand-600">ذخیره مقادیر پیش‌فرض</button>
            </div>
        </form>
    </x-common.component-card>

    <x-common.component-card title="پله‌های وزنی">
        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">وزن بسته سفارش (وزن اقلام + وزن بسته‌بندی) با بالاترین پله‌ای که از آن عبور کرده مقایسه می‌شود.</p>
            <button @click="$dispatch('open-add-tier-modal')" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm text-white hover:bg-brand-600">
                + پله جدید
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th class="py-2 text-right font-normal">حداقل وزن (گرم)</th>
                        <th class="text-right font-normal">هزینه بسته‌بندی (تومان)</th>
                        <th class="text-left font-normal">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tiers as $tier)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                            <x-tables.num class="py-2 text-gray-800 dark:text-white/90" :value="$tier->min_weight_grams" />
                            <x-tables.num class="text-gray-800 dark:text-white/90" :value="$tier->cost" />
                            <td class="text-left">
                                <button type="button" onclick="editPackagingTier({{ $tier->id }}, {{ $tier->min_weight_grams }}, {{ $tier->cost }})" class="ml-2 text-sm text-brand-500 hover:underline">ویرایش</button>
                                <form method="POST" action="{{ route('warehouse.packaging-cost.tiers.destroy', $tier) }}" class="inline"
                                    onsubmit="return confirm('این پله حذف شود؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm text-error-500 hover:underline">حذف</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-gray-400">هنوز پله‌ای تعریف نشده — همه سفارش‌ها از هزینه پیش‌فرض استفاده می‌کنند.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-common.component-card>
</div>

{{-- Add tier modal --}}
<x-ui.modal x-data="{ open: false }" @open-add-tier-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" action="{{ route('warehouse.packaging-cost.tiers.store') }}">
        @csrf
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">پله جدید</h4>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">حداقل وزن (گرم)</label>
        <input type="number" name="min_weight_grams" min="0" dir="ltr" required
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('min_weight_grams')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">هزینه بسته‌بندی (تومان)</label>
        <input type="number" name="cost" min="0" dir="ltr" required
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        @error('cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </div>
    </form>
</x-ui.modal>

{{--
    Single shared edit modal, populated by editPackagingTier() below, instead
    of one modal per tier: a Blade component tag's attribute NAME can't be
    dynamically interpolated inside a @foreach (e.g. @open-edit-tier-{{ $tier->id }}-modal)
    — the compiler can't generate its per-instance variable and breaks with a
    stray "endif". Same family of bug as the documented duplicate-x-data issue
    on <x-ui.modal>, just on the attribute name instead of its value.
--}}
<x-ui.modal x-data="{ open: false }" @open-edit-tier-modal.window="open = true" class="max-w-sm p-6">
    <form method="POST" id="edit-tier-form">
        @csrf
        @method('PUT')
        <h4 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">ویرایش پله</h4>
        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">حداقل وزن (گرم)</label>
        <input type="number" id="edit-tier-min-weight" name="min_weight_grams" min="0" dir="ltr" required
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">هزینه بسته‌بندی (تومان)</label>
        <input type="number" id="edit-tier-cost" name="cost" min="0" dir="ltr" required
            class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">

        <div class="mt-5 flex justify-end gap-3">
            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
            <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره</button>
        </div>
    </form>
</x-ui.modal>

<script>
    function editPackagingTier(id, minWeightGrams, cost) {
        document.getElementById('edit-tier-form').action = '{{ url('warehouse/packaging-cost/tiers') }}/' + id;
        document.getElementById('edit-tier-min-weight').value = minWeightGrams;
        document.getElementById('edit-tier-cost').value = cost;
        window.dispatchEvent(new CustomEvent('open-edit-tier-modal'));
    }
</script>
@endsection
