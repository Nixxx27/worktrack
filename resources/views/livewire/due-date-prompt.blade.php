{{--
    "This step needs a due date."

    Deliberately small. The card has already snapped back by the time this renders, so
    the dialog's whole job is to collect one date and get out of the way — anything
    else on it would read as a form the user has to fill in to move a card, which is
    the friction NFR-U1 warns about.

    Rendered only while $open, so a closed prompt's input is absent from the DOM
    rather than merely hidden and cannot be tab-focused.
--}}
<div>
    @if ($open && $this->project && $this->step)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-royal-900/40 p-4 sm:p-8"
             x-data
             x-on:keydown.escape.window="$wire.close()"
             x-on:click.self="$wire.close()">

            <div class="mt-16 w-full max-w-md rounded-2xl bg-surface shadow-xl"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="ddp-title">

                <form wire:submit="save">
                    <div class="rounded-t-2xl bg-royal-900 px-5 py-3.5 text-ink-inverse">
                        <h2 id="ddp-title" class="text-base font-semibold tracking-tight">
                            When is this due?
                        </h2>
                        {{-- Names both ends of the move. "Requires a due date" on its own
                             leaves the user guessing which rule they hit. --}}
                        <p class="mt-0.5 font-mono text-[11px] uppercase tracking-[0.08em] text-royal-300">
                            {{ $this->step->name }} needs one
                        </p>
                    </div>

                    <div class="space-y-4 px-5 py-4">
                        <p class="text-sm leading-relaxed text-ink-soft">
                            <span class="font-semibold text-ink">{{ $this->project->name }}</span>
                            has no due date, and
                            <span class="font-semibold text-ink">{{ $this->step->name }}</span>
                            does not accept work without one. Set it and the card moves.
                        </p>

                        <div>
                            <label for="ddp-due" class="block text-xs font-semibold text-ink">
                                Due date <span class="text-health-stalled">*</span>
                            </label>
                            {{-- Empty and autofocused: the field is the only thing on this
                                 dialog, so the caret belongs in it. No prefilled default —
                                 a date somebody accepted without reading is the exact
                                 outcome this gate exists to prevent. --}}
                            <input id="ddp-due" wire:model="dueDate" type="date" autofocus
                                   class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                            @error('dueDate') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Cancel is named for what it actually does. "Cancel" alone would
                         leave the user unsure whether the card moved, since they watched
                         it snap back a moment ago. --}}
                    <div class="flex items-center justify-end gap-2 rounded-b-2xl border-t border-line-soft bg-canvas px-5 py-3">
                        <button type="button" wire:click="close"
                                class="rounded-lg px-3 py-1.5 text-sm font-medium text-ink-soft transition hover:bg-canvas-sunken hover:text-ink">
                            Leave it in {{ $this->project->step->name }}
                        </button>
                        <button class="rounded-lg bg-royal-900 px-4 py-1.5 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                            Set date and move
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
