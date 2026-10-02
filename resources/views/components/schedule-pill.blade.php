{{--
    A card's schedule verdict as a pill — "3 days late", "due tomorrow", "No due date".

    One component for the board strip and the dashboard panel, so the same verdict is
    the same colour on both screens. Colour is severity only, from the health tokens:
    late is the stalled red, soon the at-risk amber, on schedule the on-track green.
--}}
@props(['project', 'status'])

@php
    $tone = match ($status) {
        \App\Enums\ScheduleStatus::Overdue => 'bg-health-stalled-bg text-health-stalled',
        \App\Enums\ScheduleStatus::DueSoon => 'bg-health-atrisk-bg text-health-atrisk',
        \App\Enums\ScheduleStatus::OnSchedule => 'bg-health-ontrack-bg text-health-ontrack',
        default => 'bg-canvas text-ink-soft ring-1 ring-line',
    };
@endphp

<span {{ $attributes->merge(['class' => "whitespace-nowrap rounded-full px-2 py-0.5 font-mono text-[10px] tabular-nums $tone"]) }}>
    {{ $project->target_date ? \App\Support\Duration::deadlineWords($project->target_date) : $status->label() }}
</span>
