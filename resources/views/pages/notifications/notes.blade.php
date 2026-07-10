@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="یادداشت‌ها" />

<x-common.component-card title="یادداشت‌های من">
    <div class="space-y-3">
        @forelse ($notes as $note)
            <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-1">
                    <a href="{{ route('orders.show', $note->order) }}" class="text-sm font-medium text-brand-500 hover:underline">
                        سفارش #{{ $note->order->hub_order_id }}
                    </a>
                    <span class="text-xs text-gray-400 dark:text-gray-500">
                        {{ $note->author?->name ?? 'کاربر حذف‌شده' }} — {{ \App\Domain\Accounting\Support\JalaliPeriod::humanDiff($note->created_at) }}
                    </span>
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $note->body }}</p>
                @if ($note->recipients->isNotEmpty())
                    <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                        ارسال‌شده به: {{ $note->recipients->pluck('user.name')->filter()->implode('، ') }}
                    </p>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-500">یادداشتی برای شما وجود ندارد — نه نوشته‌اید و نه به شما محول شده.</p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $notes->onEachSide(1)->links('vendor.pagination.custom') }}
    </div>
</x-common.component-card>
@endsection
