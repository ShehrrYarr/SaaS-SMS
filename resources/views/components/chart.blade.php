{{--
    An ApexCharts chart, themed from the Herozi colour tokens and redrawn when
    the light/dark theme changes. :options is a plain ApexCharts options array
    (chart.type, series, xaxis, labels...).

        <x-chart :options="['chart' => ['type' => 'bar'], 'series' => [...], 'xaxis' => ['categories' => [...]]]" height="300" />
--}}
@props(['options', 'height' => 300])

<div {{ $attributes->class('ssms-chart') }} wire:ignore
     x-data="apexChart(@js($options), {{ (int) $height }})" style="min-height: {{ (int) $height }}px">
    <div x-ref="canvas"></div>
</div>
