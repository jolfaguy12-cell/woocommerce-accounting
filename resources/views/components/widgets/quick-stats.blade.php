@props([
    'title' => null,
    'stats' => [],   // [['label'=>…, 'value'=>…, 'type'=>'toman|int|percent', 'change'=>?float, 'status'=>?string], ...]
    'columns' => 4,
])

{{--
    Compact strip of figures — the "quick statistics" widget. Each cell reuses
    <x-kpi.compact>, so the typography, trend arrow and number formatting match
    the full KPI cards exactly instead of drifting into a second style.
--}}
@php
    $grid = [
        2 => 'sm:grid-cols-2',
        3 => 'sm:grid-cols-2 lg:grid-cols-3',
        4 => 'sm:grid-cols-2 lg:grid-cols-4',
        5 => 'sm:grid-cols-3 lg:grid-cols-5',
    ][$columns] ?? 'sm:grid-cols-2 lg:grid-cols-4';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-card border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]']) }}>
    @if ($title)
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <h3 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $title }}</h3>
        </div>
    @endif

    @if (empty($stats))
        <x-states.state variant="empty" />
    @else
        <div class="grid divide-y divide-gray-100 dark:divide-gray-800 {{ $grid }} sm:divide-y-0 sm:divide-x sm:divide-x-reverse">
            @foreach ($stats as $stat)
                <div class="p-5">
                    <x-kpi.compact
                        :label="$stat['label']"
                        :value="$stat['value'] ?? null"
                        :type="$stat['type'] ?? 'int'"
                        :change="$stat['change'] ?? null"
                        :status="$stat['status'] ?? null" />
                </div>
            @endforeach
        </div>
    @endif
</div>
