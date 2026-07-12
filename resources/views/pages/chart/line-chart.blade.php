@extends('layouts.app')

@php
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $sales = [180, 190, 170, 160, 175, 165, 170, 205, 230, 210, 240, 235];
    $revenue = [40, 30, 50, 40, 55, 40, 70, 100, 110, 120, 150, 140];
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="نمودار خطی" />

    <div class="space-y-6">
        <x-common.component-card title="نمودار ناحیه‌ای">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <x-charts.chart preset="area" height="md" class="min-w-[1000px]" :categories="$months"
                    :series="[['name' => 'فروش', 'data' => $sales], ['name' => 'درآمد', 'data' => $revenue]]" />
            </div>
        </x-common.component-card>

        <x-common.component-card title="نمودار خطی">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <x-charts.chart preset="line" height="md" class="min-w-[1000px]" :categories="$months"
                    :series="[['name' => 'فروش', 'data' => $sales], ['name' => 'درآمد', 'data' => $revenue]]" />
            </div>
        </x-common.component-card>

        <x-common.component-card title="نمودار ترکیبی (میله + خط)">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <x-charts.chart preset="mixed" height="md" class="min-w-[1000px]" :categories="$months"
                    :series="[
                        ['name' => 'درآمد', 'type' => 'column', 'data' => $revenue],
                        ['name' => 'فروش', 'type' => 'line', 'data' => $sales],
                    ]" />
            </div>
        </x-common.component-card>
    </div>
@endsection
