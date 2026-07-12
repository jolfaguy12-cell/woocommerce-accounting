@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="ظاهر" parentLabel="پروفایل" :parentUrl="route('profile.edit')" />

<x-nav.tabs
    class="mb-5"
    param="tab"
    active="appearance"
    :tabs="[
        ['key' => 'profile', 'label' => 'پروفایل', 'url' => route('profile.edit')],
        ['key' => 'password', 'label' => 'تغییر رمز عبور', 'url' => route('password.edit')],
        ['key' => 'appearance', 'label' => 'ظاهر', 'url' => route('appearance')],
    ]"
/>

<div class="max-w-xl space-y-4">
    <x-common.component-card title="پوسته برنامه" desc="انتخاب شما در همین مرورگر ذخیره می‌شود.">
        {{-- Reads/writes the same Alpine theme store the header toggle uses, so the
             two stay in sync (see layouts/app.blade.php → Alpine.store('theme')). --}}
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" @click="if ($store.theme.theme === 'dark') $store.theme.toggle()"
                :class="$store.theme.theme === 'light'
                    ? 'border-brand-500 bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'"
                class="inline-flex h-11 items-center gap-2 rounded-lg border px-4 text-sm font-medium">
                روشن
            </button>

            <button type="button" @click="if ($store.theme.theme === 'light') $store.theme.toggle()"
                :class="$store.theme.theme === 'dark'
                    ? 'border-brand-500 bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                    : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'"
                class="inline-flex h-11 items-center gap-2 rounded-lg border px-4 text-sm font-medium">
                تاریک
            </button>

            <span class="text-sm text-gray-500 dark:text-gray-400">
                پوسته فعلی: <span class="font-medium" x-text="$store.theme.theme === 'dark' ? 'تاریک' : 'روشن'"></span>
            </span>
        </div>
    </x-common.component-card>
</div>
@endsection
