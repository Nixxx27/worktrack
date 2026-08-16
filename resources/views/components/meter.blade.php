{{--
    A horizontal bar, scaled against the largest value in its own group.

    A minimum width is always drawn: a bar that rounds to 0% is invisible, and an
    invisible bar reads as missing data rather than as a small number. The figure
    itself always appears as text beside the meter, so the bar is decoration for
    comparison and never the only way to read the value — which is also why this
    carries aria-hidden.
--}}
@props([
    'value' => 0,
    'max' => 1,
    'tone' => 'bg-step-active',
])

@php
    $pct = max(3, min(100, (int) round(($value / max((float) $max, 1)) * 100)));
@endphp

<div {{ $attributes->merge(['class' => 'h-1.5 overflow-hidden rounded bg-canvas-sunken']) }} aria-hidden="true">
    <div class="h-full rounded {{ $tone }}" style="width: {{ $pct }}%"></div>
</div>
