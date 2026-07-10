@extends('layouts.app')

@php
    $typeLabels = ['simple' => 'محصول ساده', 'variable' => 'محصول متغیر', 'variation' => 'تنوع محصول'];
    $sourceLabels = ['webhook' => 'وب‌هوک', 'poll' => 'دریافت دوره‌ای', 'manual' => 'دستی', 'invoice' => 'فاکتور خرید', 'import' => 'ورود گروهی'];
    $canEdit = auth()->user()->hasAnyRole(['admin', 'accountant']);
    $pricing = $product['pricing'];
    $mirroredAt = $product['sync']['mirrored_at'];
    $outdatedSync = $mirroredAt && now()->diffInHours(\Illuminate\Support\Carbon::parse($mirroredAt)) > 48;

    $warnings = [];
    if ($pricing['mapping_status'] !== 'mapped') {
        $warnings[] = ['text' => 'محصول به قلم بهای تمام‌شده نگاشت نشده است', 'tone' => 'error'];
    } elseif ($pricing['latest_cost'] === null) {
        $warnings[] = ['text' => 'بهای تمام‌شده‌ای برای این محصول ثبت نشده است', 'tone' => 'error'];
    }
    if ($pricing['retail_profit'] !== null && $pricing['retail_profit'] < 0) {
        $warnings[] = ['text' => 'سود هر واحد منفی است؛ قیمت فروش را بازبینی کنید', 'tone' => 'error'];
    }
    if ($product['stock_quantity'] !== null && $product['stock_quantity'] <= 0) {
        $warnings[] = ['text' => 'موجودی محصول صفر است', 'tone' => 'warning'];
    }
    if ($outdatedSync) {
        $warnings[] = ['text' => 'اطلاعات محصول مدتی است از هاب به‌روزرسانی نشده است', 'tone' => 'warning'];
    }
    if ($product['status'] === 'trash') {
        $warnings[] = ['text' => 'این محصول در ووکامرس داخل سطل زباله است', 'tone' => 'error'];
    }

    $pct = fn ($n) => $n === null ? '—' : number_format($n, 1).'٪';
@endphp

@section('content')
<x-common.page-breadcrumb :pageTitle="$product['name']" />

<div class="space-y-4">
    <x-common.component-card :title="$product['name']">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <x-ui.badge color="light" size="sm">{{ $typeLabels[$product['type']] ?? $product['type'] }}</x-ui.badge>
                <span>شناسه محصول: <span dir="ltr">#{{ $product['hub_product_id'] }}</span></span>
                @if ($product['sku'])
                    <span>SKU: <span dir="ltr">{{ $product['sku'] }}</span></span>
                @endif
                @if ($product['gtin'])
                    <span>GTIN: <span dir="ltr">{{ $product['gtin'] }}</span></span>
                @endif
                @if ($product['status'] === 'trash')
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-600 px-2.5 py-0.5 text-xs font-semibold text-white">در سطل زباله ووکامرس</span>
                @elseif ($product['status'])
                    <x-ui.badge color="light" size="sm">{{ $product['status'] === 'publish' ? 'منتشرشده' : $product['status'] }}</x-ui.badge>
                @endif
                @if ($product['type'] === 'variation')
                    @if ($product['parent'])
                        <a href="{{ route('products.show', $product['parent']['id']) }}" class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs text-gray-700 transition-colors hover:border-brand-300 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                            محصول والد: {{ $product['parent']['name'] }}
                        </a>
                    @else
                        <span class="text-xs text-gray-400 dark:text-gray-500">محصول والد یافت نشد</span>
                    @endif
                @endif
            </div>

            @if ($canEdit)
                <div class="flex flex-wrap items-center gap-2">
                    <button @click="$dispatch('open-wholesale-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ثبت قیمت عمده</button>
                    <button @click="$dispatch('open-cost-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ثبت بهای تمام‌شده</button>
                    <button @click="$dispatch('open-mapping-modal')" class="inline-flex h-9 items-center gap-1.5 rounded-md border border-gray-300 px-3 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">ویرایش محصول</button>
                    <form method="POST" action="{{ route('products.sync', $product['id']) }}">
                        @csrf
                        <button type="submit" class="inline-flex h-9 items-center gap-1.5 rounded-md bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">همگام‌سازی با ووکامرس</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="mt-4 space-y-1.5">
            @if (session('success'))
                <x-ui.alert variant="success" :message="session('success')" />
            @endif
            @if ($errors->has('sync'))
                <x-ui.alert variant="error" :message="$errors->first('sync')" />
            @endif
            @foreach ($warnings as $warning)
                <x-ui.alert :variant="$warning['tone'] === 'error' ? 'error' : 'warning'" :message="$warning['text']" />
            @endforeach
        </div>
    </x-common.component-card>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <x-common.component-card title="قیمت و موجودی">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">قیمت فروش</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $product['price'] !== null ? number_format($product['price']).' تومان' : '—' }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">قیمت قبل از تخفیف</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $product['regular_price'] !== null ? number_format($product['regular_price']).' تومان' : '—' }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">موجودی فعلی</span>
                    <span class="font-medium {{ $product['stock_quantity'] !== null && $product['stock_quantity'] <= 0 ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}">
                        {{ $product['stock_quantity'] !== null ? number_format($product['stock_quantity']).' عدد' : ($product['stock_status'] ?? '—') }}
                    </span>
                </div>
            </div>
        </x-common.component-card>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">سودآوری</h3>
                <x-ui.badge :color="$pricing['mapping_status'] === 'mapped' ? 'light' : 'error'" size="sm">
                    {{ $pricing['mapping_status'] === 'mapped' ? 'نگاشت‌شده' : 'بدون نگاشت' }}
                </x-ui.badge>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">قیمت تمام‌شده آخر</span>
                    <span class="font-medium {{ $pricing['latest_cost'] === null ? 'text-error-500' : 'text-gray-800 dark:text-white/90' }}">
                        {{ $pricing['latest_cost'] !== null ? number_format($pricing['latest_cost']).' تومان' : 'ثبت نشده' }}
                    </span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">سود هر واحد</span>
                    <span class="font-medium {{ $pricing['retail_profit'] === null ? 'text-gray-800 dark:text-white/90' : ($pricing['retail_profit'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-error-500') }}">
                        {{ $pricing['retail_profit'] !== null ? number_format($pricing['retail_profit']).' تومان' : '—' }}
                    </span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">حاشیه سود</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $pct($pricing['retail_margin']) }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">قیمت عمده داخلی</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $pricing['wholesale_price'] !== null ? number_format($pricing['wholesale_price']).' تومان' : '—' }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">سود عمده</span>
                    <span class="font-medium {{ $pricing['wholesale_profit'] === null ? 'text-gray-800 dark:text-white/90' : ($pricing['wholesale_profit'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-error-500') }}">
                        {{ $pricing['wholesale_profit'] !== null ? number_format($pricing['wholesale_profit']).' تومان ('.$pct($pricing['wholesale_margin']).')' : '—' }}
                    </span>
                </div>
                @if ($product['mapping']['cost_item'] ?? null)
                    <div class="flex items-center justify-between py-1.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">قلم نگاشت‌شده</span>
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $product['mapping']['cost_item'] }} × {{ number_format($product['mapping']['multiplier'], 3) }}</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">وضعیت همگام‌سازی با ووکامرس</h3>
                <x-ui.badge :color="$outdatedSync ? 'error' : 'light'" size="sm">{{ $outdatedSync ? 'قدیمی' : 'به‌روز' }}</x-ui.badge>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">آخرین تغییر در فروشگاه</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $product['sync']['hub_modified_at'] ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($product['sync']['hub_modified_at'])) : '—' }}</span>
                </div>
                <div class="flex items-center justify-between py-1.5 text-sm">
                    <span class="text-gray-500 dark:text-gray-400">آخرین به‌روزرسانی آینه</span>
                    <span class="font-medium text-gray-800 dark:text-white/90">{{ $mirroredAt ? \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($mirroredAt)) : '—' }}</span>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">داده‌ها فقط از هاب خوانده می‌شود؛ این سامانه چیزی در ووکامرس تغییر نمی‌دهد.</p>
        </div>
    </div>

    @if (count($product['variations']))
        <x-common.component-card title="تنوع‌های محصول">
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($product['variations'] as $v)
                    <a href="{{ route('products.show', $v['id']) }}" class="flex flex-col gap-1 rounded-lg border border-gray-200 p-3 text-sm transition-colors hover:border-brand-300 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5">
                        <span class="truncate font-medium text-gray-800 dark:text-white/90">{{ $v['name'] }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $v['price'] !== null ? number_format($v['price']).' تومان' : 'بدون قیمت' }} · موجودی {{ $v['stock_quantity'] !== null ? number_format($v['stock_quantity']) : '—' }}
                        </span>
                    </a>
                @endforeach
            </div>
        </x-common.component-card>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-common.component-card title="یادداشت‌های محصول">
            @if ($canEdit)
                <form method="POST" action="{{ route('products.notes', $product['id']) }}" class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/5">
                    @csrf
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">افزودن یادداشت جدید</p>
                    <div class="grid gap-3 sm:grid-cols-[1fr_8rem]">
                        <div>
                            <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">عنوان یادداشت</label>
                            <input type="text" name="title" required class="h-9 w-full rounded-md border border-gray-300 bg-transparent px-2 text-sm dark:border-gray-700 dark:text-white/90">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">ضریب (اختیاری)</label>
                            <input type="number" step="0.001" min="0.001" name="multiplier" dir="ltr" class="h-9 w-full rounded-md border border-gray-300 bg-transparent px-2 text-sm dark:border-gray-700 dark:text-white/90">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">متن یادداشت</label>
                        <textarea name="body" rows="2" class="w-full rounded-md border border-gray-300 bg-transparent px-2 py-1.5 text-sm dark:border-gray-700 dark:text-white/90"></textarea>
                    </div>
                    <div>
                        <button type="submit" class="h-9 rounded-md bg-brand-500 px-4 text-sm font-medium text-white hover:bg-brand-600">ذخیره یادداشت</button>
                    </div>
                </form>
            @endif

            <div class="mt-4">
                @if (count($product['notes']) === 0)
                    <p class="py-2 text-center text-sm text-gray-500 dark:text-gray-400">هنوز یادداشتی ثبت نشده است</p>
                @else
                    <ol class="relative space-y-4 border-s border-gray-200 ps-4 dark:border-gray-800">
                        @foreach ($product['notes'] as $note)
                            <li class="relative">
                                <span class="absolute -start-[21px] top-1.5 size-2.5 rounded-full border-2 border-white bg-brand-500 dark:border-gray-900"></span>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $note['title'] }}</span>
                                    @if ($note['multiplier'] !== null)
                                        <x-ui.badge color="light" size="sm">ضریب {{ number_format($note['multiplier'], 3) }}</x-ui.badge>
                                    @endif
                                </div>
                                @if ($note['body'])
                                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $note['body'] }}</p>
                                @endif
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $note['author'] ?? '—' }} · {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($note['created_at'])) }}
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </x-common.component-card>

        <x-common.component-card title="تاریخچه خرید">
            @if ($product['purchase_history']->isEmpty())
                <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">هنوز خریدی برای قلم این محصول ثبت نشده است</p>
            @else
                <div class="space-y-1 text-sm">
                    @foreach ($product['purchase_history'] as $row)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-1.5 last:border-0 dark:border-gray-800">
                            <span class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::parse($row->effective_at)->format('Y/m/d') }}
                                <x-ui.badge color="light" size="sm">{{ $sourceLabels[$row->source] ?? $row->source }}</x-ui.badge>
                            </span>
                            <span class="whitespace-nowrap font-medium text-gray-800 dark:text-white/90">{{ number_format($row->landed_unit_cost) }} تومان</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-common.component-card>
    </div>

    <x-common.component-card title="تغییرات ثبت‌شده">
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 text-sm font-medium text-gray-500 dark:text-gray-400">تاریخچه قیمت</h3>
                @if ($product['price_history']->isEmpty())
                    <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">هنوز تغییری ثبت نشده است</p>
                @else
                    <div class="space-y-1 text-sm">
                        @foreach ($product['price_history'] as $h)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-1.5 last:border-0 dark:border-gray-800">
                                <span class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($h->changed_at)) }}
                                    <x-ui.badge color="light" size="sm">{{ $sourceLabels[$h->source] ?? $h->source }}</x-ui.badge>
                                </span>
                                <span class="whitespace-nowrap text-sm text-gray-800 dark:text-white/90" dir="ltr">
                                    {{ $h->old_price !== null ? number_format($h->old_price) : '—' }} ← {{ $h->new_price !== null ? number_format($h->new_price) : '—' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <h3 class="mb-2 text-sm font-medium text-gray-500 dark:text-gray-400">تاریخچه موجودی</h3>
                @if ($product['stock_history']->isEmpty())
                    <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">هنوز تغییری ثبت نشده است</p>
                @else
                    <div class="space-y-1 text-sm">
                        @foreach ($product['stock_history'] as $h)
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-1.5 last:border-0 dark:border-gray-800">
                                <span class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Domain\Accounting\Support\JalaliPeriod::fmtDateTime(\Illuminate\Support\Carbon::parse($h->changed_at)) }}
                                    <x-ui.badge color="light" size="sm">{{ $sourceLabels[$h->source] ?? $h->source }}</x-ui.badge>
                                </span>
                                <span class="whitespace-nowrap text-sm text-gray-800 dark:text-white/90" dir="ltr">
                                    {{ $h->old_quantity !== null ? number_format($h->old_quantity) : '—' }} ← {{ $h->new_quantity !== null ? number_format($h->new_quantity) : '—' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </x-common.component-card>
</div>

@if ($canEdit)
    {{-- Wholesale price modal --}}
    <x-ui.modal :isOpen="$errors->has('price')" @open-wholesale-modal.window="open = true" class="max-w-sm p-6">
        <form method="POST" action="{{ route('products.wholesale', $product['id']) }}">
            @csrf
            <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت قیمت عمده داخلی</h4>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">این قیمت فقط داخلی است و هرگز به ووکامرس ارسال نمی‌شود.</p>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">قیمت عمده (تومان)</label>
            <input type="number" name="price" min="0" dir="ltr" required value="{{ $pricing['wholesale_price'] }}"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            @error('price')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            <div class="mt-5 flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ثبت قیمت عمده</button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Cost entry modal --}}
    <x-ui.modal :isOpen="$errors->hasAny(['unit_cost', 'effective_at', 'supplier_party_id', 'new_supplier_name'])" @open-cost-modal.window="open = true" class="max-w-sm p-6">
        <form method="POST" action="{{ route('products.cost', $product['id']) }}">
            @csrf
            <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ثبت بهای تمام‌شده</h4>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                {{ ($product['mapping']['cost_item'] ?? null) ? 'بهای جدید برای قلم «'.$product['mapping']['cost_item'].'» ثبت می‌شود.' : 'ابتدا محصول را به قلم بهای تمام‌شده نگاشت کنید.' }}
            </p>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">بهای هر واحد (تومان)</label>
            <input type="number" name="unit_cost" min="1" dir="ltr" required value="{{ old('unit_cost') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            @error('unit_cost')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">تاریخ خرید (اختیاری — پیش‌فرض امروز)</label>
            <input type="text" inputmode="none" placeholder="امروز" autocomplete="off" data-jdp
                data-jdp-target-value-input="#cost-effective-at-g" data-jdp-target-value-type="gregorian"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
            <input type="hidden" id="cost-effective-at-g" name="effective_at" value="{{ old('effective_at') }}">
            @error('effective_at')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <label class="mb-1.5 mt-4 block text-sm font-medium text-gray-700 dark:text-gray-400">تامین‌کننده (اختیاری)</label>
            <select name="supplier_party_id" onchange="document.getElementById('new-supplier-name-wrap').classList.toggle('hidden', this.value !== '__new__')"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">بدون تامین‌کننده</option>
                @foreach ($suppliers as $s)
                    <option value="{{ $s->id }}" @selected(old('supplier_party_id') == $s->id)>{{ $s->name }}{{ $s->shop_name ? " ({$s->shop_name})" : '' }}</option>
                @endforeach
                <option value="__new__" @selected(old('new_supplier_name'))>+ تامین‌کننده جدید…</option>
            </select>
            @error('supplier_party_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <div id="new-supplier-name-wrap" class="mt-4 {{ old('new_supplier_name') ? '' : 'hidden' }}">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام تامین‌کننده جدید</label>
                <input type="text" name="new_supplier_name" value="{{ old('new_supplier_name') }}" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('new_supplier_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-400">اطلاعات کامل‌تر (فروشگاه، تلفن، شماره حساب) را می‌توانید بعداً از «مدیریت تامین‌کننده‌ها» تکمیل کنید.</p>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" @disabled(! ($product['mapping']['cost_item_id'] ?? null)) class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">ثبت بهای تمام‌شده</button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Cost mapping modal --}}
    {{--
        Note: extra x-data passed via the component tag below is silently
        dropped — <x-ui.modal>'s own template already hardcodes an x-data on
        that same root element, and duplicate HTML attributes keep only the
        first. So the new-item-name toggle uses plain vanilla JS instead of
        a second (unreachable) Alpine scope.
    --}}
    <x-ui.modal :isOpen="$errors->hasAny(['cost_item_id', 'new_item_name']) || (old('_form') === 'mapping' && $errors->has('multiplier'))" @open-mapping-modal.window="open = true" class="max-w-md p-6">
        <form method="POST" action="{{ route('products.map', $product['id']) }}">
            @csrf
            <input type="hidden" name="_form" value="mapping">
            <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">ویرایش اطلاعات داخلی محصول</h4>
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">اطلاعات فروشگاه فقط از هاب خوانده می‌شود؛ اینجا نگاشت بهای تمام‌شده و ضریب واحد ویرایش می‌شود.</p>

            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">قلم بهای تمام‌شده</label>
            <select name="cost_item_id" onchange="document.getElementById('new-item-name-wrap').classList.toggle('hidden', this.value !== '')"
                class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                <option value="">قلم جدید…</option>
                @foreach ($costItems as $item)
                    <option value="{{ $item->id }}" @selected(($product['mapping']['cost_item_id'] ?? null) == $item->id)>{{ $item->name }}{{ $item->sku ? " ({$item->sku})" : '' }}</option>
                @endforeach
            </select>
            @error('cost_item_id')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror

            <div id="new-item-name-wrap" class="mt-4 {{ ($product['mapping']['cost_item_id'] ?? null) ? 'hidden' : '' }}">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">نام قلم جدید</label>
                <input type="text" name="new_item_name" class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('new_item_name')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="mt-4">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">ضریب (تعداد واحد در هر فروش)</label>
                <input type="number" step="0.001" min="0.001" name="multiplier" dir="ltr" value="{{ $product['mapping']['multiplier'] ?? 1 }}"
                    class="h-11 w-full rounded-lg border border-gray-300 bg-white px-4 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                @error('multiplier')<p class="mt-1 text-xs text-error-500">{{ $message }}</p>@enderror
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300">انصراف</button>
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">ذخیره تغییرات</button>
            </div>
        </form>
    </x-ui.modal>
@endif
@endsection
