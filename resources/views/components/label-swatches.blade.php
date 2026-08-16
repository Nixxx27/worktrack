{{--
    The label palette, as a row of pressable swatches.

    Used twice — once for the label being created, once against an existing label
    being repainted — and the two must look and behave identically, because to the
    person clicking they are the same act: choosing what this label looks like.

    A radio group rather than a list of buttons: exactly one colour is in effect at
    a time, so the selected swatch has to be announced as selected, not merely drawn
    with a ring. `method`/`prefix` build the Livewire call — prefix carries whatever
    leading argument the handler needs (the tag id, for a recolour).
--}}
@props([
    'palette',
    'selected' => null,
    'method',
    'prefix' => '',
    'label' => 'Label colour',
])

<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-1.5']) }} role="radiogroup" aria-label="{{ $label }}">
    @foreach ($palette as $name => $hex)
        @php $on = $selected === $hex; @endphp
        <button type="button"
                wire:click="{{ $method }}({{ $prefix }}'{{ $hex }}')"
                wire:key="swatch-{{ $method }}-{{ $loop->index }}"
                role="radio"
                aria-checked="{{ $on ? 'true' : 'false' }}"
                aria-label="{{ $name }}"
                title="{{ $name }}"
                class="size-6 rounded-full ring-offset-1 ring-offset-surface transition hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-royal-600 {{ $on ? 'ring-2 ring-ink' : 'ring-1 ring-black/10' }}"
                style="background-color: {{ $hex }}">
            {{-- The tick keeps the selection legible without relying on the ring alone,
                 which is a colour-on-colour cue at 24px. --}}
            <span class="text-[11px] font-bold leading-none text-white">{{ $on ? '✓' : '' }}</span>
        </button>
    @endforeach
</div>
