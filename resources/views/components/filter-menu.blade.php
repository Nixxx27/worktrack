{{--
    A board filter that takes more than one value.

    Native <select multiple> was the cheap option and is the wrong one: it hides its
    selected rows the moment the list is longer than the box, and ctrl-clicking to add
    a second name is folk knowledge, not an affordance. A summary button that opens a
    checkbox list says what is applied without being opened, which is the property the
    filter bar is built around — a board someone else looks at must never be readable
    as the whole picture when it is not.

    Alpine owns only open/closed. Every value is wire:model, so the selection survives
    a re-render and lands in the query string like every other filter.
--}}

@props([
    'title',            // the accessible name — "Filter by assignee"
    'empty',            // what the trigger reads when nothing is picked
    'model',            // the Livewire list property this menu writes to
    'options',          // value => label, in the order they should be listed
    'selected' => [],   // the values currently applied, already validated
    'noun',             // plural, for the "3 assignees" summary
])

@php
    $options = collect($options);
    $selected = collect($selected);

    // One name is worth more than "1 assignee"; past that the count is the only thing
    // that fits a filter bar, and the detail is one click away inside the menu.
    $summary = match (true) {
        $selected->isEmpty() => $empty,
        $selected->count() === 1 => $options->get($selected->first(), $empty),
        default => $selected->count().' '.$noun,
    };
@endphp

<div class="relative"
     wire:key="filter-menu-{{ $model }}"
     x-data="{ open: false }"
     x-on:keydown.escape.stop="open = false; $refs.trigger.focus()">

    {{-- Carries the applied state in its own styling: a quiet control that has
         something switched on has to look different from one that does not, or the
         only signal left is the summary text, which reads as a label. --}}
    <button type="button"
            x-ref="trigger"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="true"
            aria-label="{{ $title }}"
            class="flex max-w-56 items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-sm transition {{ $selected->isNotEmpty() ? 'border-royal-600 bg-powder-200 font-medium text-royal-900' : 'border-line-strong bg-surface text-ink hover:border-powder-500' }}">
        <span class="truncate">{{ $summary }}</span>
        <span aria-hidden="true" class="text-[10px] text-ink-faint">&#9662;</span>
    </button>

    <div x-show="open"
         x-cloak
         x-on:click.outside="open = false"
         role="group"
         aria-label="{{ $title }}"
         class="absolute left-0 top-full z-20 mt-1.5 w-60 rounded-xl border border-line-strong bg-surface p-1.5 shadow-xl">

        @if ($options->isEmpty())
            <p class="px-2 py-1.5 text-sm text-ink-faint">Nothing to filter by yet.</p>
        @else
            {{-- Scrolls rather than growing: a tracker with thirty members would
                 otherwise push the menu off the bottom of the board. --}}
            <div class="max-h-64 overflow-y-auto">
                @foreach ($options as $value => $text)
                    {{-- Whole-row targets rather than a bare 16px box, same as the
                         project dialog: a checkbox alone is well under a comfortable
                         hit area, and these get clicked in threes. --}}
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition hover:bg-powder-100 {{ $selected->contains($value) ? 'font-medium text-royal-900' : 'text-ink-soft' }}">
                        {{-- Checked server-side as well as by wire:model. Livewire sets
                             it on hydration anyway, but a board opened from a shared
                             link renders filtered before that happens, and a ticked row
                             next to an empty box for the half-second in between reads
                             as a bug. --}}
                        <input type="checkbox"
                               wire:model.live="{{ $model }}"
                               value="{{ $value }}"
                               @checked($selected->contains($value))
                               class="size-4 shrink-0 rounded border-line-strong text-royal-700 focus:ring-royal-600">
                        <span class="truncate">{{ $text }}</span>
                    </label>
                @endforeach
            </div>

            @if ($selected->isNotEmpty())
                {{-- Unchecking four boxes one at a time is four round trips. This is
                     one, and it is the only way to empty a menu without hunting for
                     which boxes are ticked. --}}
                <div class="mt-1 border-t border-line pt-1">
                    <button type="button"
                            wire:click="clearFilter('{{ $model }}')"
                            class="w-full rounded-lg px-2 py-1.5 text-left text-sm font-medium text-royal-800 transition hover:bg-powder-100">
                        Clear {{ $noun }}
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
