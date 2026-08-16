{{--
    FR-8.11 — a project name on the dashboard, linked to its card.

    Every figure on the dashboard is about a specific piece of work, and a report you
    cannot act from is a report you stop opening: the reader was previously expected to
    memorise a name, go to the board, pick the right tracker and find it by eye.

    A real href, not a wire:click. The target is a location, so it has to survive
    middle-click, copy-link and being pasted to the person who should be chasing it.
--}}
@props(['project'])

<a href="{{ route('board', ['project' => $project->public_id]) }}"
   {{ $attributes->merge(['class' => 'rounded underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-600']) }}>
    {{ $slot }}
</a>
