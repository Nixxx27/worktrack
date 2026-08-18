{{--
    One recipient's outbox batch — FR-7.

    Every block below renders from the payload SNAPSHOT written when the event happened.
    Nothing here touches a model, which is what makes the email describe the board as it
    was at the moment it changed rather than as it looks when the mail finally goes out.

    No thematic-break separators between events on purpose: `---` after a line of text is
    a setext heading in CommonMark, not a rule, and blade directives leave whitespace
    behind that makes which one you get hard to predict. Each block leads with its own
    bold line instead.
--}}
<x-mail::message>
@if ($multiple)
# Your Worktrack digest

{{ count($events) }} updates, oldest first.
@endif

@foreach ($events as $event)
@if ($multiple)
_{{ \App\Support\Duration::dayDate($event['occurred_at']) }}, {{ $event['occurred_at']->format('H:i') }}_
@endif

@include(\App\Mail\WorktrackNotification::viewFor($event['type']), ['e' => $event, 'p' => $event['payload']])

@endforeach
<x-mail::subcopy>
Sent to {{ $recipient->email }} because you own, are assigned to, watch, or administer this work.
</x-mail::subcopy>
</x-mail::message>
