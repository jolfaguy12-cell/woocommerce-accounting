@extends('layouts.app')

@php
    use App\Domain\Accounting\Support\JalaliPeriod;

    $typeLabels = [
        'missing_cost' => 'بدون بهای تمام‌شده',
        'unmapped_product' => 'محصول بدون نگاشت',
        'unknown_source' => 'منبع ناشناخته',
        'missing_shipping' => 'هزینه حمل ناقص',
        'missing_commission' => 'کارمزد ناموجود',
        'sync_error' => 'خطای همگام‌سازی',
        'late_entry' => 'ثبت دیرهنگام',
        'possible_duplicate_customer' => 'احتمال مشتری تکراری',
    ];
@endphp

@section('content')
<x-common.page-breadcrumb pageTitle="مرکز بازبینی" />

<div class="space-y-4">
    @if (session('success'))
        <x-ui.alert variant="success" title="انجام شد" :message="session('success')" />
    @endif

    @error('new_channel_name')
        <x-ui.alert variant="error" title="خطا" :message="$message" />
    @enderror

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ number_format($items->count()) }} مورد باز
    </p>

    @forelse ($items as $item)
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <x-ui.badge :color="$item['type'] === 'sync_error' ? 'error' : 'warning'" size="sm">
                    {{ $typeLabels[$item['type']] ?? $item['type'] }}
                </x-ui.badge>
                <span class="text-xs text-gray-400">{{ JalaliPeriod::fmtDateTime($item['created_at']) }}</span>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    @if ($item['source'])
                        منبع خام:
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-800 dark:bg-white/10 dark:text-white/90" dir="ltr">{{ $item['source']['raw_value'] }}</code>
                        ({{ number_format($item['source']['order_count']) }} سفارش)
                    @else
                        <code class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">
                            {{ $item['subject_type'] }}#{{ $item['subject_id'] }}
                            {{ $item['payload'] ? Str::limit(json_encode($item['payload'], JSON_UNESCAPED_UNICODE), 120) : '' }}
                        </code>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Map an unknown source to an existing or brand-new channel --}}
                    @if ($item['type'] === 'unknown_source' && $item['source'])
                        <form method="POST" action="{{ route('review.map-source', $item['source']['id']) }}"
                            x-data="{ channelId: '' }" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <select name="channel_id" x-model="channelId"
                                class="h-9 rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">کانال جدید…</option>
                                @foreach ($channels as $channel)
                                    <option value="{{ $channel->id }}">{{ $channel->name }}</option>
                                @endforeach
                            </select>

                            {{-- Only sent when no existing channel is picked (the controller requires
                                 new_channel_name only when channel_id is absent). --}}
                            <template x-if="channelId === ''">
                                <input type="text" name="new_channel_name" required placeholder="نام کانال جدید" maxlength="100"
                                    class="h-9 w-40 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                            </template>

                            <button type="submit"
                                class="h-9 rounded-lg bg-brand-500 px-3 text-sm font-medium text-white hover:bg-brand-600">
                                اتصال
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('review.resolve', $item['id']) }}">
                        @csrf
                        <input type="hidden" name="action" value="resolved">
                        <button type="submit"
                            class="h-9 rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            حل شد
                        </button>
                    </form>

                    <form method="POST" action="{{ route('review.resolve', $item['id']) }}">
                        @csrf
                        <input type="hidden" name="action" value="dismissed">
                        <button type="submit"
                            class="h-9 rounded-lg px-3 text-sm font-medium text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            نادیده
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-2xl border border-gray-200 bg-white py-12 text-center dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-gray-500 dark:text-gray-400">همه‌چیز پاک است ✅</p>
        </div>
    @endforelse
</div>
@endsection
