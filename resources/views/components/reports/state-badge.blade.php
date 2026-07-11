@props(['state'])

@php
    $presented = \App\Domain\Reports\Support\ReportStatePresenter::state($state);
@endphp

<x-ui.badge :color="$presented['color']" size="sm">{{ $presented['label'] }}</x-ui.badge>
