{{-- FR-7.4. The excerpt is a content snapshot, so a later edit cannot rewrite an email
     that has already been sent — which also means the quote may not match the card. That
     is the correct trade: an email is a record of what was said to you at the time. --}}
**You were mentioned**@isset($p['project_name']) on {{ $p['project_name'] }}@endisset@isset($p['tracker_name']) · {{ $p['tracker_name'] }}@endisset

@foreach ($p['mentions'] ?? [] as $mention)
> {{ $mention['excerpt'] ?? '' }}
@isset($mention['by'])
— {{ $mention['by'] }}
@endisset

@endforeach
[Open the card]({{ $e['link'] }})
