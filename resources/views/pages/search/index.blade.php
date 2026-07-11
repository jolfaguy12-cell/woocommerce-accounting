@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="جستجو" />

<div class="space-y-5">
    <!-- Search Box -->
    <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03] sm:p-5">
        <form method="GET" action="{{ route('search.index') }}">
            <div class="relative">
                <span class="absolute -translate-y-1/2 pointer-events-none right-4 top-1/2 text-gray-400 dark:text-gray-500">
                    <svg class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                            fill="" />
                    </svg>
                </span>
                <input type="text" name="q" value="{{ $query }}" autofocus placeholder="جستجو ..."
                    class="dark:bg-dark-900 h-12 w-full rounded-xl border border-gray-200 bg-transparent py-2.5 pr-12 pl-4 text-base text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-800 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
            </div>
        </form>
    </div>

    <!-- Results -->
    @if ($query === '')
        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 py-16 text-center dark:border-gray-800">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
                <svg width="22" height="22" viewBox="0 0 20 20" fill="none">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                        d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                        fill="currentColor" />
                </svg>
            </span>
            <p class="text-sm text-gray-500 dark:text-gray-400">برای شروع، نام سفارش، محصول یا مشتری را جستجو کنید.</p>
        </div>
    @elseif ($results->isEmpty())
        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-gray-200 py-16 text-center dark:border-gray-800">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.21967 7.28131L17.7782 17.7794M17.7782 7.28131L6.21967 17.7794" />
                </svg>
            </span>
            <p class="text-sm text-gray-500 dark:text-gray-400">نتیجه‌ای برای «{{ $query }}» پیدا نشد.</p>
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $results->count() }} نتیجه برای «{{ $query }}»</p>

        <div class="flex flex-col gap-2">
            @foreach ($results as $result)
                <a href="{{ $result['url'] }}"
                    class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">
                    <x-ui.badge :color="$result['badge_color']" size="sm">{{ $result['type_label'] }}</x-ui.badge>

                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate font-medium text-gray-800 dark:text-white/90">{{ $result['title'] }}</span>
                        @if ($result['subtitle'])
                            <span class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $result['subtitle'] }}</span>
                        @endif
                    </span>

                    <svg class="shrink-0 text-gray-400 dark:text-gray-500" width="18" height="18" viewBox="0 0 17 16" fill="none">
                        <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
