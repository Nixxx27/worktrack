{{--
    One headline figure with its label and a line of context.

    The context line is required by convention rather than by code: a bare number on a
    dashboard invites the reader to invent its denominator. "12" means nothing until it
    says "across all trackers".

    `alert` turns the border and the figure to the stalled tone. Reserved for a count
    that is bad *because it is not zero* — overdue work, stalled work — so a coloured
    tile always means "look here" and never "this tile is a different topic".
--}}
@props([
    'label',
    'value',
    'note' => null,
    'alert' => false,
])

<div @class([
    'rounded-xl border bg-surface p-4 shadow-sm',
    'border-health-stalled/40' => $alert,
    'border-line' => ! $alert,
])>
    <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">{{ $label }}</p>
    <p @class(['mt-1.5 font-mono text-2xl tabular-nums', 'text-health-stalled' => $alert])>
        {{ $value }}
    </p>
    @if ($note)
        <p class="mt-0.5 text-[11px] text-ink-faint">{{ $note }}</p>
    @endif
</div>
