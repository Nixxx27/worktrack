{{--
    FR-4.10 — the universal search palette.

    An overlay rather than a wider box in the filter bar, and the reason is what the two
    controls are for. The filter bar changes the board you are looking at, so it has to
    be readable without being opened — a filtered board someone else glances at must
    never pass for the whole picture. This does the opposite: it takes you somewhere
    else and then gets out of the way, so it earns none of that permanent real estate
    and would push four filters onto a second row to get it.

    Alpine owns open/closed, focus and the arrow keys; Livewire owns the term and the
    results. Nothing about opening the palette needs the server, and a round trip before
    the caret appears is the difference between a tool people reach for and one they
    navigate to the board instead of using.

    KNOWN LIMITATION, shared with the project dialog and the due-date prompt: focus is
    not trapped, so Tab past the last result reaches the page behind. Left consistent
    with them rather than trapped here alone — one dialog behaving unlike the other two
    is the kind of drift this header exists to prevent. The panel is display:none while
    closed (x-show, and x-cloak before Alpine hydrates), so nothing inside it is
    tab-reachable until it is opened, which is the half that would actually be a bug.
--}}
<div x-data="{
        open: false,
        /* The shortcut is shown, not just bound: a hidden keystroke is one only its
           author uses. Chosen at runtime because ⌘K on Windows is nothing at all. */
        hint: '⌘K',

        init() {
            this.hint = /Mac|iPhone|iPad/.test(navigator.platform ?? '') ? '⌘K' : 'Ctrl K';
        },

        show() {
            this.open = true;
            /* After the paint, or there is nothing focusable yet. Select rather than
               place the caret: reopening with a stale term should let you retype over
               it, which is what every palette has trained people to expect. */
            this.$nextTick(() => { this.$refs.input?.focus(); this.$refs.input?.select(); });
        },

        hide() {
            this.open = false;
            this.$refs.trigger?.focus();
        },

        /* Roving focus over the results. Real links, so Enter needs no handler of its
           own — and Tab still works for anyone who does not know about the arrows. */
        move(direction) {
            const items = [...this.$refs.panel.querySelectorAll('[data-result]')];

            if (! items.length) {
                return;
            }

            const at = items.indexOf(document.activeElement);

            /* From the input (at === -1), Down goes to the first result and Up to the
               last — the wrap costs one expression and saves reaching for the mouse. */
            const next = at === -1
                ? (direction > 0 ? 0 : items.length - 1)
                : (at + direction + items.length) % items.length;

            items[next].focus();
        },
     }"
     x-on:keydown.window.cmd.k.prevent="open ? hide() : show()"
     x-on:keydown.window.ctrl.k.prevent="open ? hide() : show()"
     class="flex items-center">

    {{-- ── the trigger ─────────────────────────────────────────────────────────
         Sits with the tools rather than the destinations: search is a thing you
         operate, not a place you go, and it carries an icon on that same rule. --}}
    <button type="button"
            x-ref="trigger"
            x-on:click="show()"
            aria-haspopup="dialog"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-label="Search all trackers"
            class="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-powder-300">
        <svg class="size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <line x1="16.5" y1="16.5" x2="21" y2="21"/>
        </svg>
        <span class="hidden sm:inline">Search</span>
        {{-- The keystroke, on the control it operates. Hidden on touch, where there is
             no keyboard to press it with and the pill would be decoration. --}}
        <kbd x-text="hint"
             class="ml-0.5 hidden rounded border border-royal-700 bg-royal-800 px-1 py-px font-mono text-[10px] font-normal text-royal-300 lg:inline"></kbd>
    </button>

    {{-- ── the palette ─────────────────────────────────────────────────────────
         z-50, above the sticky header's z-20. Escape is bound here rather than on the
         window so it cannot swallow the key from a dialog opened on top of this one. --}}
    <div x-show="open"
         x-cloak
         x-on:keydown.escape.stop="hide()"
         x-on:keydown.down.prevent="move(1)"
         x-on:keydown.up.prevent="move(-1)"
         class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]">

        {{-- Dimmed, so the palette reads as the only live thing on the screen. Clicking
             it closes: for a control this transient, "somewhere else" is a dismissal. --}}
        <div x-on:click="hide()" aria-hidden="true" class="absolute inset-0 bg-royal-950/40 backdrop-blur-[1px]"></div>

        <div x-ref="panel"
             role="dialog"
             aria-modal="true"
             aria-label="Search all trackers"
             class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-line-strong bg-surface shadow-2xl">

            <div class="flex items-center gap-2.5 border-b border-line px-4 py-3">
                <svg class="size-4 flex-none text-ink-faint" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/>
                    <line x1="16.5" y1="16.5" x2="21" y2="21"/>
                </svg>

                {{-- type="text", not "search": the native clear affordance is a second,
                     differently-shaped Clear button beside the one below, and Escape
                     inside a type="search" clears the field instead of closing. --}}
                <input x-ref="input"
                       wire:model.live.debounce.250ms="term"
                       type="text"
                       autocomplete="off"
                       spellcheck="false"
                       aria-label="Search cards across every tracker"
                       placeholder="Search every tracker — names, labels, checklists, comments…"
                       class="flex-1 border-0 bg-transparent p-0 text-[15px] text-ink placeholder:text-ink-faint focus:outline-none">

                @if ($term !== '')
                    <button type="button"
                            wire:click="clear"
                            x-on:click="$refs.input.focus()"
                            class="rounded-lg px-2 py-0.5 text-xs font-medium text-ink-soft transition hover:bg-powder-100 hover:text-royal-800">
                        Clear
                    </button>
                @endif
            </div>

            {{-- max-h + scroll rather than growing: twelve results on a laptop would
                 otherwise run the panel off the bottom of the viewport. --}}
            <div class="max-h-[52vh] overflow-y-auto p-1.5">
                @php $found = $this->results; @endphp

                @if ($term === '')
                    <p class="px-3 py-6 text-center text-sm text-ink-faint">
                        Type to search every tracker you can see.
                    </p>
                @elseif ($this->tooShort)
                    {{-- Says what is missing, not that nothing was found. "No results"
                         for a one-character term is a lie about the data. --}}
                    <p class="px-3 py-6 text-center text-sm text-ink-faint">
                        Keep typing — two characters or more.
                    </p>
                @elseif ($found['rows']->isEmpty())
                    <p class="px-3 py-6 text-center text-sm text-ink-soft">
                        Nothing matches <span class="font-medium text-ink">{{ $term }}</span>.
                        <span class="mt-1 block text-xs text-ink-faint">
                            Archived cards are not searched, and neither are trackers you are not a member of.
                        </span>
                    </p>
                @else
                    {{-- Announced, because the list changes under a caret that never moved:
                         without this a screen reader hears nothing after the first keystroke. --}}
                    <p class="sr-only" aria-live="polite">
                        {{ $found['rows']->count() }} {{ \Illuminate\Support\Str::plural('result', $found['rows']->count()) }}
                    </p>

                    @foreach ($found['rows'] as $result)
                        @php
                            $project = $result['project'];

                            // Same token pairs as the board card and the drawer, so a flag
                            // means the same thing wherever it is read.
                            $tone = match ($project->health->value) {
                                'on_track' => 'bg-health-ontrack-bg text-health-ontrack',
                                'at_risk' => 'bg-health-atrisk-bg text-health-atrisk',
                                'stalled' => 'bg-health-stalled-bg text-health-stalled',
                                'on_hold' => 'bg-health-onhold-bg text-health-onhold',
                            };
                        @endphp

                        {{-- FR-8.11's URL, not a wire:click: the target is a location, so it
                             has to survive middle-click, copy-link and being pasted to
                             whoever should be chasing it. The board resolves which tracker
                             the card is on, so one href works across all of them.

                             A plain anchor rather than <x-project-link>, despite sharing the
                             route. That component styles an inline name inside prose — it
                             underlines on hover and rounds to `rounded` — and both fight a
                             whole-row target that indicates hover with a background instead.
                             Merged attributes cannot lose that argument cleanly, and bending
                             the shared component to win it would restyle every figure on the
                             dashboard. The thing worth sharing here is the URL, and it is. --}}
                        <a href="{{ route('board', ['project' => $project->public_id]) }}"
                           data-result
                           wire:key="hit-{{ $project->public_id }}"
                           class="block rounded-xl px-3 py-2 transition hover:bg-powder-100 focus-visible:bg-powder-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-royal-600">
                            <div class="flex items-baseline gap-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ $project->name }}</span>

                                <span class="flex-none rounded-full px-1.5 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wider {{ $tone }}">
                                    {{ str_replace('_', ' ', $project->health->value) }}
                                </span>
                            </div>

                            {{-- Which board, then which column. A result you cannot place is
                                 a result you have to click to identify — and across trackers
                                 the step name alone is ambiguous, since two trackers may
                                 both have an "In Progress" that means different things. --}}
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 font-mono text-[11px] text-ink-faint">
                                <span class="text-ink-soft">{{ $project->tracker->name }}</span>
                                <span aria-hidden="true">&rsaquo;</span>
                                <span>{{ $project->step->name }}</span>

                                @if ($project->target_date)
                                    <span aria-hidden="true">·</span>
                                    <span class="{{ $project->isOverdue() ? 'font-semibold text-health-stalled' : '' }}">
                                        {{ $project->isOverdue() ? 'overdue' : 'due' }}
                                        {{ $project->target_date->format('D j M') }}
                                    </span>
                                @endif
                            </div>

                            @if ($result['hint'])
                                {{-- The whole licence for searching comments and checklists.
                                     A result whose visible text does not contain the term
                                     reads as a bug until it says why it is here. --}}
                                <p class="mt-0.5 text-[11px] italic text-ink-faint">{{ $result['hint'] }}</p>
                            @endif
                        </a>
                    @endforeach

                    @if ($found['truncated'])
                        <p class="border-t border-line px-3 pb-1 pt-2 text-[11px] text-ink-faint">
                            Showing the {{ $found['rows']->count() }} most recently active matches — narrow the term to see fewer.
                        </p>
                    @endif
                @endif
            </div>

            {{-- The arrow keys, said once where they apply. Hidden on touch with the
                 shortcut pill, for the same reason. --}}
            <div class="hidden items-center gap-3 border-t border-line bg-canvas px-4 py-1.5 font-mono text-[10px] text-ink-faint lg:flex">
                <span><kbd class="rounded border border-line-strong bg-surface px-1">↑</kbd><kbd class="ml-0.5 rounded border border-line-strong bg-surface px-1">↓</kbd> move</span>
                <span><kbd class="rounded border border-line-strong bg-surface px-1">enter</kbd> open</span>
                <span><kbd class="rounded border border-line-strong bg-surface px-1">esc</kbd> close</span>
            </div>
        </div>
    </div>
</div>
