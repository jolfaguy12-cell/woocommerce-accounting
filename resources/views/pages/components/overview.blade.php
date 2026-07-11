@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="کامپوننت‌ها" />

<div class="space-y-6">
    <x-common.component-card title="نمای کلی کامپوننت‌ها" desc="فهرست همه کامپوننت‌های قابل‌استفاده مجدد در معماری فعلی Blade + Alpine. هر کامپوننت شناسه پایدار (مثل card-01) و یک عبارت ارجاع دارد که می‌توانید در درخواست‌های بعدی از آن استفاده کنید.">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($categories as $key => $category)
                <a href="{{ route('components.category', $key) }}"
                    class="group flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-700">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400">
                            {!! \App\Helpers\MenuHelper::getIconSvg($category['icon']) !!}
                        </span>
                        <div>
                            <p class="font-medium text-gray-800 dark:text-white/90">{{ $category['title'] }}</p>
                            <p class="text-xs text-gray-400">{{ $countByCategory[$key] ?? 0 }} کامپوننت</p>
                        </div>
                    </div>
                    <svg class="h-5 w-5 text-gray-300 transition group-hover:text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            @endforeach
        </div>
    </x-common.component-card>

    <x-ui.alert variant="info" title="قرارداد شناسه‌گذاری">
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            شناسه‌ها (card-01، table-01، chart-01، …) پایدار هستند و هرگز تغییر نمی‌کنند. شماره‌گذاری در هر دسته مستقل است.
            کامپوننت‌های جدید Blade باید به این نمایشگاه اضافه شوند و شماره بعدیِ خالیِ همان دسته را بگیرند.
        </p>
    </x-ui.alert>
</div>
@endsection
