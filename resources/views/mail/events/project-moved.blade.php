{{-- FR-7.2 — tracker, project, from-step → to-step, who moved it, how long it sat, link. --}}
**{{ $p['project_name'] ?? 'A project' }}**@isset($p['tracker_name']) · {{ $p['tracker_name'] }}@endisset

{{ $p['from_step'] ?? 'Created' }} → **{{ $p['to_step'] ?? '—' }}**@isset($p['moved_by']), moved by {{ $p['moved_by'] }}@endisset

@if (! empty($p['seconds_in_previous_step']) && ! empty($p['from_step']))
It sat in {{ $p['from_step'] }} for {{ \App\Support\Duration::words($p['seconds_in_previous_step']) }}.
@endif

@if (($p['coalesced'] ?? 1) > 1)
{{-- FR-7.8 — say so, rather than quietly describing the net move as if it were the
     only one. A reader who watched the card move three times and got one email should
     be able to tell the email knows that. --}}
This card moved {{ $p['coalesced'] }} times in quick succession; above is where it started and where it ended up.
@endif

[Open the card]({{ $e['link'] }})
