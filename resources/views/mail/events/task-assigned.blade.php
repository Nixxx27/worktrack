{{-- FR-5.4. Lists every task when several were assigned inside one window, because
     hearing only about the latest would leave the earlier ones assigned, on the board,
     and never announced to the person expected to do them. --}}
**Assigned to you**@isset($p['project_name']) on {{ $p['project_name'] }}@endisset@isset($p['tracker_name']) · {{ $p['tracker_name'] }}@endisset

@if (! empty($p['tasks']))
@foreach ($p['tasks'] as $task)
- {{ $task }}
@endforeach
@else
- {{ $p['task_title'] ?? 'A task' }}
@endif

@if (! empty($p['due_date']))
Due {{ \App\Support\Duration::dayDate(\Illuminate\Support\Carbon::parse($p['due_date'])) }}.
@endif
@isset($p['assigned_by'])
Assigned by {{ $p['assigned_by'] }}.
@endisset

[Open the card]({{ $e['link'] }})
