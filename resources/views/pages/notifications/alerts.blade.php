@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="اعلان‌های سیستم" />

<x-common.component-card title="اعلان‌های من">
    <div class="space-y-3">
        @forelse ($deliveries as $delivery)
            <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800 {{ $delivery->resolved_at ? 'opacity-60' : '' }}">
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-1">
                    <span class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $delivery->event->alertType->name ?? 'هشدار سیستم' }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($delivery->created_at) }}</span>
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $delivery->event->rendered_message }}</p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    @if ($delivery->resolved_at)
                        <x-ui.badge color="success" size="sm">برطرف‌شده</x-ui.badge>
                    @endif
                    @if ($delivery->event->url)
                        <a href="{{ $delivery->event->url }}" class="text-xs text-brand-500 hover:underline">مشاهده</a>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-500">اعلانی برای شما وجود ندارد.</p>
        @endforelse
    </div>
</x-common.component-card>
@endsection
