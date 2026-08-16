{{--
    A titled card. Every dashboard section is one of these, and they were nine
    hand-copied copies of the same six classes before this existed — which is how one
    of them ended up with a slate divider while the other eight used the bone token.

    The header slot takes a control or a small inline chart that belongs beside the
    title rather than inside the body.
--}}
@props([
    'heading' => null,
    'note' => null,     {{-- the one-line explanation under the heading --}}
])

<section {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-line bg-surface shadow-sm']) }}>
    @if ($heading)
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2 border-b border-line-soft px-5 py-3.5">
            <div>
                <h2 class="text-sm font-semibold">{{ $heading }}</h2>
                @if ($note)
                    <p class="mt-0.5 text-xs text-ink-soft">{{ $note }}</p>
                @endif
            </div>
            {{ $header ?? '' }}
        </div>
    @endif

    {{ $slot }}
</section>
