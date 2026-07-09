@props([
    'headers' => [],
    'paginator' => null,
    'emptyMessage' => 'موردی یافت نشد',
])

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <table class="w-full">
            <thead>
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    @foreach ($headers as $header)
                        {{-- Headers may be a plain string (always shown) or ['key' => ..., 'label' => ...]
                             so a page can drive column visibility from an Alpine `visible` object
                             defined on an ancestor element (see orders/index.blade.php). --}}
                        <th @if (is_array($header)) x-show="visible['{{ $header['key'] }}']" @endif class="px-5 py-3 text-right sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">{{ is_array($header) ? $header['label'] : $header }}</p>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                {{ $slot }}

                @if ($paginator !== null && $paginator->isEmpty())
                    <tr>
                        <td colspan="{{ count($headers) }}" class="px-5 py-8 text-center text-gray-500 dark:text-gray-400">
                            {{ $emptyMessage }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>

@if ($paginator !== null && $paginator->hasPages())
    <div class="mt-4">
        {{ $paginator->onEachSide(1)->links('vendor.pagination.custom') }}
    </div>
@endif
