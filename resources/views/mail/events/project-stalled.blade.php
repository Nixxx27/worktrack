{{-- FR-4.6 / US-4. The idle duration is measured to the moment of DETECTION, not to
     now: a digest can go out a day after the sweep, and "idle for 8 days" quietly
     becoming "9" would make the email disagree with the watchlist that produced it. --}}
@php
    $idleSeconds = ! empty($p['idle_since'])
        ? (int) \Illuminate\Support\Carbon::parse($p['idle_since'])->diffInSeconds($e['occurred_at'])
        : null;
@endphp
**{{ $p['project_name'] ?? 'A project' }} has stalled**@isset($p['tracker_name']) · {{ $p['tracker_name'] }}@endisset

@if ($idleSeconds !== null)
No activity for {{ \App\Support\Duration::words($idleSeconds) }}.
@else
No activity past its stall threshold.
@endif
It stays on the watchlist until something happens on it — a comment, a task, an edit or a move.

[Open the card]({{ $e['link'] }})
