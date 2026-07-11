@extends('layouts.app')

@section('content')
<x-common.page-breadcrumb pageTitle="{{ $meta['title'] }}" parentLabel="کامپوننت‌ها" parentUrl="{{ route('components.overview') }}" />

<div class="space-y-6">
    {{-- Category switcher --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($categories as $key => $category)
            <a href="{{ route('components.category', $key) }}"
                @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-brand-500 text-white' => $key === $categoryKey,
                    'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $key !== $categoryKey,
                ])>
                {{ $category['title'] }}
            </a>
        @endforeach
    </div>

    {{-- NB: loop var must not be `$component` — inside a component slot that
         name is reserved for the component instance and shadows the loop. --}}
    @forelse ($components as $entry)
        <x-showcase.item :meta="$entry" :noun="$meta['noun']">
            <x-showcase.example :id="$entry['id']" :mock="$mock" />
        </x-showcase.item>
    @empty
        <x-common.component-card title="{{ $meta['title'] }}">
            <p class="text-sm text-gray-500 dark:text-gray-400">کامپوننتی در این دسته ثبت نشده است.</p>
        </x-common.component-card>
    @endforelse
</div>
@endsection
