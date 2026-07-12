@extends('layouts.app')

@php
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="نمودار میله‌ای" />

    <div class="space-y-6">
        <x-common.component-card title="نمودار میله‌ای ساده">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <x-charts.chart
                    preset="bar"
                    height="md"
                    class="min-w-[1000px]"
                    :categories="$months"
                    :series="[168, 385, 201, 298, 187, 195, 291, 110, 215, 390, 280, 112]" />
            </div>
        </x-common.component-card>

        <x-common.component-card title="نمودار میله‌ای انباشته">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <x-charts.chart
                    preset="bar-stacked"
                    height="md"
                    class="min-w-[1000px]"
                    :categories="array_slice($months, 0, 8)"
                    :series="[
                        ['name' => 'مستقیم', 'data' => [44, 55, 41, 67, 22, 43, 55, 41]],
                        ['name' => 'ارجاعی', 'data' => [13, 23, 20, 8, 13, 27, 13, 23]],
                        ['name' => 'جستجوی ارگانیک', 'data' => [11, 17, 15, 15, 21, 14, 18, 20]],
                    ]" />
            </div>
        </x-common.component-card>
    </div>
@endsection
