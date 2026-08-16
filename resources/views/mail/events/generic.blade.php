{{-- Fallback for an event type with no partial of its own.

     FR-7.4 names events that have no writer yet (added to a tracker, account approved,
     the weekly stalled summary), and OutboxWriter will grow to cover them. Whichever of
     those ships first must produce a usable email on the day it is written rather than
     an exception inside the mailer that burns the row's retries on a template nobody has
     noticed is missing. --}}
**Worktrack update**@isset($p['project_name']) · {{ $p['project_name'] }}@endisset

{{ ucfirst(str_replace(['.', '_', '-'], ' ', $e['type'])) }}

[Open Worktrack]({{ $e['link'] }})
