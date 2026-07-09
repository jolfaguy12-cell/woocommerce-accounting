@props([
    'type', // 'order' | 'financial' | 'profit' | 'payment'
    'value',
])

@php
    $presented = match ($type) {
        'order' => \App\Domain\Orders\Support\OrderStatusPresenter::orderStatus($value),
        'financial' => \App\Domain\Orders\Support\OrderStatusPresenter::financialState($value),
        'profit' => \App\Domain\Orders\Support\OrderStatusPresenter::profitStatus($value),
        'payment' => \App\Domain\Orders\Support\OrderStatusPresenter::paymentStatus($value),
    };
@endphp

<x-ui.badge :color="$presented['color']" size="sm">{{ $presented['label'] }}</x-ui.badge>
