@extends('layouts.app')

@php
    $persianMonths = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $monthlyCounts = array_values($monthlyOrderCounts ?? []);
@endphp

@section('content')
  <div class="grid grid-cols-12 gap-4 md:gap-6">
    <div class="col-span-12 space-y-6 xl:col-span-7">
      <x-ecommerce.ecommerce-metrics :kpis="$kpis" :can-see-financials="$canSeeFinancials" />
      <x-ecommerce.monthly-sale :categories="$persianMonths" :series="$monthlyCounts" />
    </div>
    <div class="col-span-12 xl:col-span-5">
        <x-ecommerce.monthly-target />
    </div>

    <div class="col-span-12">
      <x-ecommerce.statistics-chart />
    </div>

    <div class="col-span-12">
      <x-ecommerce.recent-orders :orders="$recentOrders" />
    </div>
  </div>
@endsection
