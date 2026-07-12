@props(['available' => [], 'future' => [], 'missing' => []])

<div class="grid gap-4">
    @foreach ([
        ['title' => 'امکانات آماده (بک‌اند موجود است)', 'items' => $available, 'color' => 'text-success-600 dark:text-success-500'],
        ['title' => 'پیشنهاد برای آینده', 'items' => $future, 'color' => 'text-gray-500 dark:text-gray-400'],
        ['title' => 'نیازمند بک‌اند/تصمیم قبل از پیاده‌سازی نهایی', 'items' => $missing, 'color' => 'text-warning-600 dark:text-warning-500'],
    ] as $section)
        @if (count($section['items']))
            <div class="rounded-card border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="mb-3 text-base font-semibold {{ $section['color'] }}">{{ $section['title'] }}</h3>
                <ul class="list-disc space-y-1.5 pr-5 text-sm text-gray-500 dark:text-gray-400">
                    @foreach ($section['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach
</div>
