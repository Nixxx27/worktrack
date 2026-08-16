{{--
    The footer line every signed-in screen ends on. It carries the route to the
    activity log, which is why that link is not in the top nav: the record reads
    better introduced next to the work it records than as a fourth destination.

    The slot takes page-specific content — the board passes its step-colour legend —
    and the activity sentence is always pushed to the right so it sits in the same
    place on every page.
--}}
<footer {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line pt-2 font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint']) }}>
    {{ $slot }}

    <span class="ml-auto normal-case tracking-normal">
        Every move and edit is recorded in the
        <a href="{{ route('activity') }}" class="text-royal-700 underline underline-offset-2 hover:text-royal-900">activity log</a>.
    </span>
</footer>
