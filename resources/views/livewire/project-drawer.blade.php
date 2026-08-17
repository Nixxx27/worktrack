{{--
    FR-4.5 — the card, opened.

    A right-hand drawer rather than a centre modal: the board stays visible behind it,
    so the card keeps its context in the column it came from. Rendered only while open,
    so nothing here is tab-reachable when it is closed.

    Two columns inside it. The left is the card: everything you READ — members, dates,
    priority, labels, description — sits above the tabs and is always on screen, and
    the tabs below hold only the three things that are lists (checklist, files,
    history). Nothing about a card costs a click to find out.

    The right is the conversation, and it is a COLUMN rather than a tab on purpose:
    a comment is a reply to what someone is looking at, and a comment box you have to
    navigate away from the checklist to reach is a comment that gets sent in chat
    instead, where the card will never see it.
--}}
<div>
    @if ($open && $this->project)
        @php
            $project = $this->project;
            $tone = match ($project->health->value) {
                'on_track' => 'bg-health-ontrack-bg text-health-ontrack',
                'at_risk' => 'bg-health-atrisk-bg text-health-atrisk',
                'stalled' => 'bg-health-stalled-bg text-health-stalled',
                'on_hold' => 'bg-health-onhold-bg text-health-onhold',
            };
            $days = (int) $project->current_step_entered_at->diffInDays(now());
            $overdue = $project->target_date
                && $project->current_step_type->value !== 'terminal'
                && $project->target_date->isPast();
            $initials = fn ($name) => \Illuminate\Support\Str::of($name)
                ->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
            $tabs = ['tasks' => 'Checklist', 'files' => 'Files', 'history' => 'History'];
            $canEdit = auth()->user()->can('update', $project);
            $attachedTagIds = $project->tags->pluck('id');
            // Owner first, then assignees, in the order the avatars are drawn. Deduped
            // because owning a card you are also assigned to is ordinary, and the same
            // person twice under two identical circles reads as a bug.
            $memberNames = collect([$project->owner?->name])
                ->concat($project->assignees->pluck('name'))
                ->filter()->unique()->values();
        @endphp

        <div class="fixed inset-0 z-40 flex justify-end bg-royal-950/40"
             x-data
             x-on:keydown.escape.window="$wire.close()"
             x-on:click.self="$wire.close()">

            <aside class="flex h-full w-full max-w-5xl flex-col bg-surface shadow-2xl"
                   role="dialog" aria-modal="true" aria-labelledby="drawer-title">

                {{-- ── header ──────────────────────────────────────────────── --}}
                <div class="flex-none bg-royal-900 px-5 py-3.5 text-ink-inverse">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-royal-300">
                                {{ $project->tracker->name }} · {{ $project->step->name }}
                            </p>
                            <h2 id="drawer-title" class="mt-0.5 text-lg font-semibold leading-snug">{{ $project->name }}</h2>
                        </div>

                        <div class="flex flex-none items-center gap-1">
                            {{-- Watching is a preference about your own inbox, so any member
                                 may toggle it, Viewers included. --}}
                            @can('watch', $project)
                                <button wire:click="toggleWatch"
                                        class="rounded-lg px-2.5 py-1 text-xs font-medium transition {{ $this->isWatching ? 'bg-powder-300 text-royal-900' : 'text-royal-200 hover:bg-royal-800 hover:text-ink-inverse' }}"
                                        aria-pressed="{{ $this->isWatching ? 'true' : 'false' }}">
                                    {{ $this->isWatching ? '★ Watching' : '☆ Watch' }}
                                </button>
                            @endcan

                            @can('update', $project)
                                <button wire:click="edit"
                                        class="rounded-lg px-2.5 py-1 text-xs font-medium text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse">
                                    Edit
                                </button>
                            @endcan

                            {{-- FR-4.9. Only offered while the card is live — once it is
                                 archived the banner below carries the only action left,
                                 so the two can never both be on screen contradicting
                                 each other. --}}
                            @if (! $project->archived_at)
                                @can('archive', $project)
                                    <button wire:click="archiveProject"
                                            wire:confirm="Archive “{{ $project->name }}”? It comes off the board and out of the dashboard, but keeps its full history and can be restored."
                                            class="rounded-lg px-2.5 py-1 text-xs font-medium text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse">
                                        Archive
                                    </button>
                                @endcan
                            @endif

                            <button wire:click="close"
                                    class="rounded-lg px-2 py-1 text-lg leading-none text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse"
                                    aria-label="Close">&times;</button>
                        </div>
                    </div>

                    <div class="tnum mt-2.5 flex flex-wrap items-center gap-2 font-mono text-[11px] text-royal-200">
                        {{-- FR-4.6 — health is set by a person, with a reason, and is
                             independent of which column the card is in. The control lives
                             ON the pill rather than in a panel elsewhere: this badge is
                             where health is read, so it is where health gets changed. --}}
                        @can('setHealth', $project)
                            <div class="relative"
                                 x-data
                                 @if ($healthEditor) x-on:click.outside="$wire.closeHealthEditor()" @endif>
                                <button wire:click="{{ $healthEditor ? 'closeHealthEditor' : 'openHealthEditor' }}"
                                        aria-expanded="{{ $healthEditor ? 'true' : 'false' }}"
                                        aria-label="Change health flag"
                                        class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider transition hover:ring-2 hover:ring-royal-300/40 {{ $tone }}">
                                    {{ str_replace('_', ' ', $project->health->value) }}
                                    <span aria-hidden="true" class="ml-0.5 opacity-60">▾</span>
                                </button>

                                @if ($healthEditor)
                                    <form wire:submit="saveHealth"
                                          class="absolute left-0 top-full z-20 mt-2 w-80 rounded-xl border border-line-strong bg-surface p-3 text-ink shadow-xl">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold text-ink">Health flag</p>
                                            <button type="button" wire:click="closeHealthEditor" aria-label="Close health flag"
                                                    class="rounded px-1.5 text-sm leading-none text-ink-faint hover:bg-powder-200">&times;</button>
                                        </div>
                                        <p class="mt-0.5 font-sans text-[11px] leading-snug text-ink-faint">
                                            Setting this by hand overrides automatic stall detection until someone changes it back.
                                            On Hold suppresses auto-stall entirely.
                                        </p>
                                        <div class="mt-2 space-y-2 font-sans">
                                            <select wire:model="healthValue" aria-label="Health"
                                                    class="w-full rounded-lg border border-line-strong bg-surface px-2.5 py-2 text-sm">
                                                <option value="on_track">On track</option>
                                                <option value="at_risk">At risk</option>
                                                <option value="stalled">Stalled</option>
                                                <option value="on_hold">On hold</option>
                                            </select>
                                            <input wire:model="healthReason" type="text" placeholder="Why? (optional)"
                                                   class="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm">
                                            <button class="w-full rounded-lg bg-royal-900 px-3.5 py-2 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                                                Set
                                            </button>
                                        </div>
                                        @error('healthValue') <p class="mt-1 font-sans text-xs text-health-stalled">{{ $message }}</p> @enderror
                                        @error('healthReason') <p class="mt-1 font-sans text-xs text-health-stalled">{{ $message }}</p> @enderror
                                    </form>
                                @endif
                            </div>
                        @else
                            <span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider {{ $tone }}">
                                {{ str_replace('_', ' ', $project->health->value) }}
                            </span>
                        @endcan
                        <span>{{ $days }}d in step</span>

                        {{-- The due date is in DATES and the task count is on the Checklist
                             tab, both of them a few pixels below this and neither of them able
                             to scroll away. Repeating either here only made the line read as
                             one unpunctuated run of numbers. Overdue is the exception: that is
                             an alarm, and an alarm belongs in the chrome that never moves. --}}
                        @if ($overdue)
                            <span aria-hidden="true" class="text-royal-400">·</span>
                            <span class="font-semibold text-powder-300">
                                overdue since {{ $project->target_date->format('j M Y') }}
                            </span>
                        @endif
                    </div>

                    @if ($project->health_reason)
                        <p class="mt-2 rounded-lg bg-royal-800 px-2.5 py-1.5 text-xs text-royal-100">
                            “{{ $project->health_reason }}”
                        </p>
                    @endif
                </div>

                {{-- ── archived banner (FR-4.9) ─────────────────────────────────
                     The card is off the board, so this drawer — reached from a link,
                     the dashboard, or the moment of archiving — is the only place it
                     can still be seen. It therefore has to say plainly that the work
                     is not lost, and carry the way back. --}}
                @if ($project->archived_at)
                    <div class="flex flex-none flex-wrap items-center justify-between gap-3 border-b border-amber-300 bg-amber-50 px-5 py-2.5"
                         role="status">
                        <p class="text-[13px] leading-snug text-amber-900">
                            <span class="font-semibold">Archived</span>
                            {{ $project->archived_at->timezone(config('worktrack.default_timezone'))->format('j M Y') }}.
                            It is off the board and out of the dashboard, and its history is intact.
                        </p>

                        @can('archive', $project)
                            <button wire:click="restoreProject"
                                    class="flex-none rounded-lg bg-amber-900 px-3 py-1.5 text-xs font-medium text-ink-inverse transition hover:bg-amber-800">
                                Restore to the board
                            </button>
                        @endcan
                    </div>
                @endif

                {{-- ── two columns ─────────────────────────────────────────── --}}
                <div class="flex min-h-0 flex-1 flex-col lg:flex-row">

                    {{-- ══ MAIN ═══════════════════════════════════════════════ --}}
                    <div class="flex min-h-0 flex-1 flex-col">

                        {{-- ── members, dates, priority & labels (FR-4.1, FR-4.2, FR-4.3) ──
                             What anyone opening a card came to read: who has it, when it is
                             due, how urgent it is, what it is tagged. All above the tabs,
                             because a fact you have to go looking for under "Details" is a
                             fact the person glancing at the card will simply not have. --}}
                        <div class="flex flex-none flex-wrap items-start gap-x-6 gap-y-3 border-b border-line bg-canvas px-5 py-3">
                            <div>
                                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-faint">Members</p>
                                <div class="mt-1.5 flex items-center gap-1">
                                    {{-- Owner in royal, assignees in powder — accountable vs
                                         doing, the same pairing the card front uses, so the
                                         two colours mean one thing across the product. --}}
                                    @if ($project->owner)
                                        <span class="grid size-7 place-items-center rounded-full bg-royal-900 text-[10px] font-semibold text-ink-inverse"
                                              title="Owner: {{ $project->owner->name }}">{{ $initials($project->owner->name) }}</span>
                                    @endif

                                    @foreach ($project->assignees as $person)
                                        <span class="grid size-7 place-items-center rounded-full bg-powder-400 text-[10px] font-semibold text-royal-900"
                                              title="Assigned: {{ $person->name }}">{{ $initials($person->name) }}</span>
                                    @endforeach

                                    {{-- Only when there is genuinely nobody. An owner with no
                                         assignees is not an unassigned card, and saying so beside
                                         the owner's own avatar contradicted the avatar. --}}
                                    @if ($memberNames->isEmpty())
                                        <span class="text-sm text-ink-faint">Nobody assigned</span>
                                    @endif
                                </div>

                                {{-- Initials are not names, so the circles need a caption. It has
                                     to cover everyone in the row: listing the assignees alone put
                                     one name under two avatars and read as a mislabel of the
                                     owner. Title attribute because it truncates. --}}
                                @if ($memberNames->isNotEmpty())
                                    <p class="mt-1 max-w-56 truncate text-[11px] text-ink-faint"
                                       title="{{ $memberNames->implode(', ') }}">{{ $memberNames->implode(', ') }}</p>
                                @endif
                            </div>

                            {{-- Dates read as a span, not two isolated fields: "when it started
                                 → when it is due" is the question, and a start date on its own
                                 answers none of it. Clicking opens the edit dialog, which is
                                 where dates are actually changed. --}}
                            <div>
                                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-faint">Dates</p>
                                {{-- One markup block for both cases: a read-only viewer and an
                                     editor must see the same dates, and two copies of this is
                                     how they stop being the same. --}}
                                @php $dateTag = $canEdit ? 'button' : 'p'; @endphp

                                <{{ $dateTag }} @if ($canEdit) wire:click="edit" aria-label="Edit start and due dates" @endif
                                    class="tnum -mx-1.5 mt-1.5 block rounded-lg border border-transparent px-1.5 py-1 text-left font-mono text-sm @if ($canEdit) transition hover:border-line-strong hover:bg-surface @endif">
                                    <span class="{{ $project->start_date ? 'text-ink' : 'text-ink-faint' }}">
                                        {{ $project->start_date?->format('j M Y') ?? 'No start' }}
                                    </span>
                                    <span class="text-ink-faint">→</span>
                                    <span class="{{ $project->target_date ? ($overdue ? 'font-semibold text-health-stalled' : 'text-ink') : 'text-ink-faint' }}">
                                        {{ $project->target_date?->format('j M Y') ?? 'No due date' }}
                                    </span>
                                </{{ $dateTag }}>

                                @if ($overdue)
                                    <p class="mt-0.5 text-[11px] font-medium text-health-stalled">Overdue</p>
                                @endif
                            </div>

                            {{-- Priority sits beside the dates because it is only ever read
                                 against them: "due Friday" means something different at
                                 urgent than at low. Editing it is the edit dialog's job,
                                 same as the dates, so this click goes to the same place. --}}
                            <div>
                                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-faint">Priority</p>
                                @php
                                    $priorityTone = match ($project->priority) {
                                        'urgent' => 'bg-health-stalled-bg text-health-stalled',
                                        'high' => 'bg-health-atrisk-bg text-health-atrisk',
                                        default => 'bg-canvas-sunken text-ink-soft',
                                    };
                                    $priorityTag = $canEdit ? 'button' : 'p';
                                @endphp

                                <{{ $priorityTag }} @if ($canEdit) wire:click="edit" aria-label="Edit priority" @endif
                                    class="-mx-1.5 mt-1.5 block rounded-lg border border-transparent px-1.5 py-1 text-left @if ($canEdit) transition hover:border-line-strong hover:bg-surface @endif">
                                    <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $priorityTone }}">
                                        {{ $project->priority }}
                                    </span>
                                </{{ $priorityTag }}>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-faint">Labels</p>

                                <div class="relative mt-1.5 flex flex-wrap items-center gap-1.5"
                                     x-data
                                     @if ($labelPicker) x-on:click.outside="$wire.closeLabelPicker()" @endif>

                                    @foreach ($project->tags as $tag)
                                        <span wire:key="chip-{{ $tag->id }}"
                                              class="group inline-flex items-center gap-1 rounded px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-white"
                                              style="background-color: {{ $tag->color ?? '#55607d' }}">
                                            {{ $tag->name }}
                                            @if ($canEdit)
                                                <button wire:click="removeLabel({{ $tag->id }})"
                                                        aria-label="Remove label {{ $tag->name }}"
                                                        class="-mr-0.5 rounded px-0.5 leading-none opacity-0 transition hover:bg-black/25 focus-visible:opacity-100 group-hover:opacity-100">&times;</button>
                                            @endif
                                        </span>
                                    @endforeach

                                    @if (! $canEdit && $project->tags->isEmpty())
                                        <span class="text-sm text-ink-faint">None</span>
                                    @endif

                                    @if ($canEdit)
                                        <button wire:click="{{ $labelPicker ? 'closeLabelPicker' : 'openLabelPicker' }}"
                                                aria-expanded="{{ $labelPicker ? 'true' : 'false' }}"
                                                aria-label="Add a label"
                                                class="grid size-7 place-items-center rounded border border-line-strong bg-surface text-sm leading-none text-ink-soft transition hover:border-royal-600 hover:text-royal-800">+</button>
                                    @endif

                                    {{-- ── label picker ─────────────────────── --}}
                                    @if ($labelPicker && $canEdit)
                                        <div class="absolute left-0 top-full z-10 mt-2 w-72 rounded-xl border border-line-strong bg-surface p-3 shadow-xl">
                                            <div class="flex items-center justify-between">
                                                <p class="text-xs font-semibold text-ink">Labels</p>
                                                <button wire:click="closeLabelPicker" aria-label="Close labels"
                                                        class="rounded px-1.5 text-sm leading-none text-ink-faint hover:bg-powder-200">&times;</button>
                                            </div>

                                            <form wire:submit="createLabel" class="mt-2">
                                                <label for="dr-label" class="sr-only">Find or create a label</label>
                                                <input id="dr-label" wire:model.live.debounce.200ms="labelSearch" type="text"
                                                       placeholder="Find or create a label…" autocomplete="off"
                                                       class="w-full rounded-lg border border-line-strong bg-surface px-2.5 py-1.5 text-sm transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                                                @error('labelSearch') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                                            </form>

                                            <ul class="mt-2 max-h-52 space-y-1 overflow-y-auto">
                                                @forelse ($this->trackerTags as $tag)
                                                    @php
                                                        $on = $attachedTagIds->contains($tag->id);
                                                        $recoloring = $recoloringTagId === $tag->id;
                                                    @endphp
                                                    <li wire:key="pick-{{ $tag->id }}">
                                                        <div class="flex items-center gap-1">
                                                            <button wire:click="toggleLabel({{ $tag->id }})"
                                                                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                                                                    class="flex min-w-0 flex-1 items-center gap-2 rounded-lg px-1.5 py-1 text-left transition hover:bg-canvas">
                                                                <span class="grid size-4 flex-none place-items-center rounded border border-line-strong text-[9px] leading-none {{ $on ? 'border-royal-700 bg-royal-800 text-white' : '' }}">
                                                                    {{ $on ? '✓' : '' }}
                                                                </span>
                                                                <span class="min-w-0 flex-1 truncate rounded px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-white"
                                                                      style="background-color: {{ $tag->color ?? '#55607d' }}">{{ $tag->name }}</span>
                                                            </button>

                                                            {{-- Separate from the row, because recolouring is not tagging:
                                                                 one changes this card, the other changes the label wherever
                                                                 it appears. Clicking the chip must never do the second. --}}
                                                            <button wire:click="toggleRecolor({{ $tag->id }})"
                                                                    aria-expanded="{{ $recoloring ? 'true' : 'false' }}"
                                                                    aria-label="Change colour of {{ $tag->name }}"
                                                                    title="Change colour"
                                                                    class="grid size-6 flex-none place-items-center rounded text-ink-faint transition hover:bg-powder-200 hover:text-ink {{ $recoloring ? 'bg-powder-200 text-ink' : '' }}">
                                                                <svg viewBox="0 0 16 16" class="size-3.5" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                                                    <path d="M11 2.5 13.5 5 5.5 13H3v-2.5z" stroke-linejoin="round"/>
                                                                </svg>
                                                            </button>
                                                        </div>

                                                        @if ($recoloring)
                                                            <div class="mb-1 mt-1.5 rounded-lg bg-canvas p-2">
                                                                <x-label-swatches :palette="$this->palette"
                                                                                  :selected="$tag->color"
                                                                                  method="recolorLabel"
                                                                                  prefix="{{ $tag->id }}, "
                                                                                  label="Colour for {{ $tag->name }}" />
                                                                <p class="mt-1.5 text-[11px] text-ink-faint">
                                                                    Changes this label on every card in the tracker.
                                                                </p>
                                                            </div>
                                                        @endif
                                                    </li>
                                                @empty
                                                    <li class="px-1.5 py-2 text-xs text-ink-faint">
                                                        {{ trim($labelSearch) === '' ? 'No labels in this tracker yet.' : 'No label matches that.' }}
                                                    </li>
                                                @endforelse
                                            </ul>

                                            @if ($this->canCreateLabel)
                                                {{-- The palette appears only once there is something to create, so the
                                                     picker stays a picker for the common case of ticking a label that
                                                     already exists. --}}
                                                <div class="mt-2 border-t border-line pt-2">
                                                    <p class="text-[11px] font-medium text-ink-soft">Colour</p>

                                                    <x-label-swatches class="mt-1.5"
                                                                      :palette="$this->palette"
                                                                      :selected="$this->newLabelColor"
                                                                      method="chooseLabelColor"
                                                                      label="Colour for the new label" />

                                                    <button wire:click="createLabel"
                                                            class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg bg-royal-900 px-3 py-1.5 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                                                        Create
                                                        <span class="max-w-40 truncate rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white"
                                                              style="background-color: {{ $this->newLabelColor ?? '#55607d' }}">{{ trim($labelSearch) }}</span>
                                                    </button>
                                                </div>
                                            @endif

                                            <p class="mt-2 text-[11px] text-ink-faint">
                                                Labels belong to this tracker. Everyone filtering the board sees the same list.
                                            </p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- ── description ─────────────────────────────────────────
                             Above the tabs, not inside one. This is the answer to "what is
                             this and why does it exist", and it was the single most useful
                             thing on the card sitting behind the click nobody made.

                             Clamped to three lines and expanded in the browser: a long brief
                             must not push the checklist off screen, and a round trip to read
                             two more sentences is a round trip nobody waits for. --}}
                        @if ($project->description)
                            <div class="flex-none border-b border-line bg-canvas px-5 py-2.5"
                                 x-data="{ expanded: false, clipped: false }"
                                 x-init="$nextTick(() => clipped = $refs.body.scrollHeight > $refs.body.clientHeight + 1)">
                                <p x-ref="body"
                                   class="whitespace-pre-wrap text-sm leading-relaxed text-ink-soft"
                                   x-bind:class="expanded ? '' : 'line-clamp-3'">{{ $project->description }}</p>

                                {{-- Only offered when there is in fact more to see, so a
                                     one-line description does not grow a dead control. --}}
                                <button x-show="clipped" x-cloak
                                        x-on:click="expanded = ! expanded"
                                        x-text="expanded ? 'Show less' : 'Show more'"
                                        class="mt-1 text-[11px] font-medium text-royal-800 underline-offset-2 hover:underline"></button>
                            </div>
                        @endif

                        {{-- ── tabs ────────────────────────────────────────── --}}
                        <nav class="flex flex-none gap-1 border-b border-line bg-canvas px-3 pt-2" role="tablist">
                            @foreach ($tabs as $key => $label)
                                @php
                                    // Checklist carries progress rather than size — "3/3" is the
                                    // figure the header used to repeat, and this strip is just as
                                    // permanently on screen as the header was. Files stays a plain
                                    // count: there is nothing to be part-way through about a file.
                                    $count = match ($key) {
                                        'tasks' => $project->tasks_total
                                            ? $project->tasks_done.'/'.$project->tasks_total
                                            : null,
                                        'files' => $this->attachments->count(),
                                        default => null,
                                    };
                                @endphp
                                <button wire:click="setTab('{{ $key }}')"
                                        role="tab"
                                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                        class="-mb-px rounded-t-lg border-b-2 px-3 py-2 text-sm transition {{ $tab === $key ? 'border-royal-700 font-semibold text-royal-900' : 'border-transparent text-ink-soft hover:text-royal-800' }}">
                                    {{ $label }}@if ($count)<span class="tnum ml-1 font-mono text-[11px] text-ink-faint">{{ $count }}</span>@endif
                                </button>
                            @endforeach
                        </nav>

                        {{-- ── body ────────────────────────────────────────── --}}
                        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">

                        {{-- ══ CHECKLIST ══════════════════════════════════════════ --}}
                        @if ($tab === 'tasks')
                            @can('createTask', $project)
                                <form wire:submit="addTask" class="mb-4 rounded-xl border border-line bg-canvas p-3">
                                    <label for="dr-task" class="block text-xs font-semibold text-ink">Add a task</label>
                                    <div class="mt-1.5 flex flex-wrap gap-2">
                                        <input id="dr-task" wire:model="taskTitle" type="text" placeholder="What needs doing?"
                                               class="min-w-48 flex-1 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                                        <select wire:model="taskAssignee" aria-label="Assign to"
                                                class="rounded-lg border border-line-strong bg-surface px-2.5 py-2 text-sm">
                                            <option value="">Unassigned</option>
                                            @foreach ($this->members as $member)
                                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                                            @endforeach
                                        </select>
                                        {{-- The native control stays — its picker and its keyboard
                                             handling are better than anything hand-rolled — but on
                                             its own it renders as a bare "mm/dd/yyyy", the only
                                             string in the drawer that neither says what it is for
                                             nor matches how every other date here is written. The
                                             label in front of it answers the first; the second is
                                             the browser's locale and not ours to set. --}}
                                        <label class="flex items-center gap-2 rounded-lg border border-line-strong bg-surface pl-2.5 transition focus-within:border-royal-600 focus-within:ring-2 focus-within:ring-royal-600/20">
                                            <span class="font-mono text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-faint">Due</span>
                                            <input wire:model="taskDue" type="date" aria-label="Task due date"
                                                   class="tnum rounded-r-lg border-0 bg-transparent py-2 pr-2.5 text-sm text-ink focus:outline-none focus:ring-0">
                                        </label>
                                        <button class="rounded-lg bg-royal-900 px-3.5 py-2 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                                            Add
                                        </button>
                                    </div>
                                    @error('taskTitle') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                                    @error('taskAssignee') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                                </form>
                            @endcan

                            <ul class="space-y-1.5">
                                @forelse ($this->tasks as $task)
                                    <li wire:key="task-{{ $task->id }}"
                                        class="group flex items-start gap-2.5 rounded-lg border border-line bg-surface px-3 py-2">
                                        @can('update', $task)
                                            <button wire:click="toggleTask({{ $task->id }})"
                                                    class="mt-0.5 grid size-4.5 flex-none place-items-center rounded border transition {{ $task->is_done ? 'border-step-terminal bg-step-terminal text-white' : 'border-line-strong hover:border-royal-600' }}"
                                                    aria-label="{{ $task->is_done ? 'Reopen' : 'Complete' }} {{ $task->title }}">
                                                @if ($task->is_done)<span class="text-[10px] leading-none">✓</span>@endif
                                            </button>
                                        @else
                                            <span class="mt-0.5 grid size-4.5 flex-none place-items-center rounded border border-line-strong text-[10px]">
                                                {{ $task->is_done ? '✓' : '' }}
                                            </span>
                                        @endcan

                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm {{ $task->is_done ? 'text-ink-faint line-through' : 'text-ink' }}">
                                                {{ $task->title }}
                                            </p>
                                            @if ($task->assignee || $task->due_date)
                                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-ink-faint">
                                                    @if ($task->assignee)
                                                        <span class="inline-flex items-center gap-1">
                                                            <span class="grid size-4 place-items-center rounded-full bg-powder-400 text-[8px] font-semibold text-royal-900">
                                                                {{ $initials($task->assignee->name) }}
                                                            </span>
                                                            {{ $task->assignee->name }}
                                                        </span>
                                                    @endif
                                                    @if ($task->due_date)
                                                        <span class="tnum font-mono {{ ! $task->is_done && $task->due_date->isPast() ? 'font-semibold text-health-stalled' : '' }}">
                                                            due {{ $task->due_date->format('j M') }}
                                                        </span>
                                                    @endif
                                                </p>
                                            @endif
                                        </div>

                                        <div class="flex flex-none items-center gap-0.5 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100">
                                            @can('update', $task)
                                                <button wire:click="moveTask({{ $task->id }}, 'up')" aria-label="Move {{ $task->title }} up"
                                                        class="rounded px-1.5 py-0.5 text-xs text-ink-faint hover:bg-powder-200 hover:text-royal-800">↑</button>
                                                <button wire:click="moveTask({{ $task->id }}, 'down')" aria-label="Move {{ $task->title }} down"
                                                        class="rounded px-1.5 py-0.5 text-xs text-ink-faint hover:bg-powder-200 hover:text-royal-800">↓</button>
                                            @endcan
                                            @can('delete', $task)
                                                <button wire:click="deleteTask({{ $task->id }})"
                                                        wire:confirm="Delete “{{ $task->title }}”?"
                                                        aria-label="Delete {{ $task->title }}"
                                                        class="rounded px-1.5 py-0.5 text-xs text-ink-faint hover:bg-health-stalled-bg hover:text-health-stalled">&times;</button>
                                            @endcan
                                        </div>
                                    </li>
                                @empty
                                    <li class="rounded-lg border border-dashed border-line-strong px-3 py-6 text-center text-sm text-ink-faint">
                                        No tasks yet. Breaking the work down is what makes the {{ $project->tasks_done }}/{{ $project->tasks_total }} on the card mean something.
                                    </li>
                                @endforelse
                            </ul>

                        {{-- ══ FILES ══════════════════════════════════════════════ --}}
                        @elseif ($tab === 'files')
                            @can('uploadAttachment', $project)
                                {{-- ONE FILE PER REQUEST, sequentially.

                                     Not wire:model on a multiple input, which is the obvious
                                     spelling and the wrong one: Livewire would stage the whole
                                     selection and hand $uploads all N files at once, putting N
                                     multi-second R2 PUTs inside a single request — exactly the
                                     max_execution_time death the commitUploads docblock is
                                     written to avoid. Uploading imperatively, awaiting each
                                     file, keeps $uploads at the one element it documents and
                                     makes fifty files fifty independently recoverable requests.

                                     No Upload button either. The commit happens on the
                                     updatedUploads hook, so a second click would have nothing
                                     left to submit. --}}
                                <div class="mb-4 rounded-xl border border-line bg-canvas p-3"
                                     x-data="{
                                         total: 0,
                                         done: 0,
                                         busy: false,
                                         refused: [],
                                         max: {{ (int) config('attachments.max_bytes') }},
                                         maxLabel: '{{ round(config('attachments.max_bytes') / 1048576) }} MB',

                                         human(bytes) {
                                             const units = ['B', 'KB', 'MB', 'GB'];
                                             let i = 0;
                                             while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++ }
                                             return (i === 0 ? bytes : Math.round(bytes * 10) / 10) + ' ' + units[i];
                                         },

                                         async send(event) {
                                             const picked = Array.from(event.target.files ?? []);

                                             // Cleared immediately so re-picking the same file
                                             // fires change again — otherwise a failed upload
                                             // cannot be retried without choosing something else.
                                             event.target.value = '';

                                             if (! picked.length) return;

                                             /*
                                              * Oversized files are dropped HERE, before a byte is
                                              * sent, and this is the one client-side check that is
                                              * not merely a courtesy.
                                              *
                                              * The server re-checks size and remains the
                                              * enforcement. But a file larger than PHP's
                                              * post_max_size never reaches that check: PHP
                                              * discards the whole request body, the CSRF field
                                              * with it, so the upload endpoint answers 419 and
                                              * Livewire surfaces &quot;failed to upload&quot; — no size, no
                                              * filename, nothing to act on. FR-6.8 asks for a
                                              * clear error, and for that one case this is the
                                              * only place a clear error can still be produced.
                                              */
                                             this.refused = picked
                                                 .filter((file) => file.size > this.max)
                                                 .map((file) => file.name + ' — that file is ' + this.human(file.size) + '. The limit is ' + this.maxLabel + '.');

                                             const files = picked.filter((file) => file.size <= this.max);

                                             if (! files.length) return;

                                             this.total = files.length;
                                             this.done = 0;
                                             this.busy = true;

                                             for (const file of files) {
                                                 // resolve on BOTH outcomes: one refused file must
                                                 // not stall the queue behind it. The server
                                                 // reports what it rejected in uploadErrors.
                                                 // this.$wire, not $wire: Alpine's magics are
                                                 // bound to the data proxy, so a bare $wire inside
                                                 // an x-data method is an undefined variable — and
                                                 // no PHP test can catch that.
                                                 await new Promise((resolve) => {
                                                     this.$wire.uploadMultiple('uploads', [file], resolve, resolve);
                                                 });

                                                 this.done++;
                                             }

                                             this.busy = false;
                                         },
                                     }">
                                    <label for="dr-file" class="block text-xs font-semibold text-ink">Attach files</label>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                        <input id="dr-file" type="file" multiple
                                               x-on:change="send($event)"
                                               x-bind:disabled="busy"
                                               class="flex-1 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-powder-300 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-royal-900 disabled:opacity-60">

                                        {{-- Counts files, not bytes. "3 of 12" is the number
                                             someone who just chose twelve files wants; a
                                             percentage of the current file is not. --}}
                                        <p x-show="busy" x-cloak
                                           class="tnum font-mono text-[11px] text-ink-soft"
                                           aria-live="polite"
                                           x-text="`Uploading ${done + 1} of ${total}…`"></p>
                                    </div>
                                    <p class="mt-1.5 text-[11px] text-ink-faint">
                                        Up to {{ round(config('attachments.max_bytes') / 1048576) }} MB each, no limit on how many.
                                        Documents, images, video, audio, email, text, web files (HTML, CSS, JS) and archives.
                                        Executables and system scripts are rejected.
                                    </p>

                                    {{-- Refused without ever being sent, so the server has no
                                         uploadErrors entry for these and cannot render them. --}}
                                    <template x-if="refused.length">
                                        <ul class="mt-1.5 space-y-0.5" aria-live="polite">
                                            <template x-for="(message, i) in refused" :key="i">
                                                <li class="text-xs text-health-stalled" x-text="message"></li>
                                            </template>
                                        </ul>
                                    </template>
                                </div>

                                {{-- Per-file, and NOT a field error: a batch is not pass-or-fail,
                                     so eleven files attaching while one is refused has to be
                                     legible as exactly that. Dismissable, because these survive
                                     until they are read rather than until the next render. --}}
                                @if ($uploadErrors)
                                    <div class="mb-4 rounded-xl border border-health-stalled/30 bg-health-stalled-bg px-3 py-2.5">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="text-xs font-semibold text-red-900">
                                                {{ count($uploadErrors) }} {{ \Illuminate\Support\Str::plural('file', count($uploadErrors)) }} not attached
                                            </p>
                                            <button wire:click="clearUploadErrors"
                                                    class="-mr-1 -mt-0.5 rounded px-1.5 text-sm leading-none text-red-900/60 transition hover:bg-health-stalled/10 hover:text-red-900"
                                                    aria-label="Dismiss upload errors">&times;</button>
                                        </div>
                                        <ul class="mt-1.5 space-y-0.5">
                                            @foreach ($uploadErrors as $message)
                                                <li class="text-xs text-red-900/80">{{ $message }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endcan

                            <ul class="space-y-1.5">
                                @forelse ($this->attachments as $file)
                                    <li wire:key="file-{{ $file->id }}"
                                        class="group flex items-center gap-3 rounded-lg border border-line bg-surface px-3 py-2">
                                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-canvas-sunken font-mono text-[9px] font-semibold uppercase text-ink-soft">
                                            {{ \Illuminate\Support\Str::limit($file->extension ?? 'file', 4, '') }}
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            {{-- A normal link to an authorizing route, which re-checks the
                                                 policy and only then redirects to a short-lived signed URL.
                                                 The signed URL is never rendered into the page: once minted
                                                 it is a bearer token, and a page is a place URLs get shared. --}}
                                            <a href="{{ route('attachments.download', $file->public_id) }}"
                                               class="block truncate text-sm font-medium text-royal-800 underline-offset-2 hover:underline">
                                                {{ $file->original_filename }}
                                            </a>
                                            <p class="tnum font-mono text-[11px] text-ink-faint">
                                                {{ $file->humanSize() }} · {{ $file->uploader?->name ?? 'unknown' }} · {{ $file->created_at->diffForHumans() }}
                                            </p>
                                        </div>

                                        @can('delete', $file)
                                            <button wire:click="deleteFile({{ $file->id }})"
                                                    wire:confirm="Delete “{{ $file->original_filename }}”? This removes it from storage and cannot be undone."
                                                    aria-label="Delete {{ $file->original_filename }}"
                                                    class="flex-none rounded px-1.5 py-0.5 text-xs text-ink-faint opacity-0 transition hover:bg-health-stalled-bg hover:text-health-stalled focus-visible:opacity-100 group-hover:opacity-100">
                                                &times;
                                            </button>
                                        @endcan
                                    </li>
                                @empty
                                    <li class="rounded-lg border border-dashed border-line-strong px-3 py-6 text-center text-sm text-ink-faint">
                                        No files yet.
                                    </li>
                                @endforelse
                            </ul>

                        {{-- ══ HISTORY ════════════════════════════════════════════ --}}
                        @else
                            <div class="rounded-xl border border-line bg-surface">
                                <div class="border-b border-line-soft px-3 py-2">
                                    <p class="text-sm font-semibold text-ink">Movement history</p>
                                    <p class="mt-0.5 text-[11px] text-ink-faint">
                                        FR-4.8 — the source of truth for every metric, and never edited by anyone.
                                    </p>
                                </div>
                                <ul>
                                    @foreach ($this->movements as $movement)
                                        <li class="flex flex-wrap items-baseline gap-x-2 border-b border-line-soft px-3 py-2 text-sm last:border-b-0">
                                            <span class="tnum font-mono text-[11px] text-ink-faint">#{{ $movement->seq }}</span>
                                            <span class="text-ink-soft">
                                                {{ $movement->fromStep?->name ? $movement->fromStep->name.' → ' : 'created in ' }}
                                            </span>
                                            <span class="font-medium text-ink">{{ $movement->toStep?->name }}</span>
                                            <span class="text-ink-faint">by {{ $movement->movedBy?->name ?? 'system' }}</span>
                                            <span class="tnum ml-auto font-mono text-[11px] text-ink-faint"
                                                  title="{{ $movement->entered_at->toDayDateTimeString() }}">
                                                {{ $movement->entered_at->diffForHumans() }}
                                                @if ($movement->duration_seconds !== null)
                                                    · {{ \App\Support\Duration::words($movement->duration_seconds) }}
                                                @else
                                                    · still here
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        </div>
                    </div>

                    {{-- ══ COMMENTS AND ACTIVITY ══════════════════════════════ --}}
                    <section class="flex min-h-0 w-full flex-none flex-col border-t border-line bg-canvas lg:w-96 lg:border-l lg:border-t-0"
                             aria-labelledby="drawer-feed-title">

                        <div class="flex flex-none items-center gap-2 px-4 pb-2 pt-3">
                            <h3 id="drawer-feed-title" class="flex-1 text-sm font-semibold text-ink">Comments and activity</h3>
                            {{-- Trello's "show details", named for what it actually hides.
                                 Collapsing the trail leaves the conversation alone, which is
                                 what you want on a card that has been dragged across the
                                 board fifteen times. --}}
                            <button wire:click="toggleActivity"
                                    aria-pressed="{{ $showActivity ? 'true' : 'false' }}"
                                    class="rounded-lg border border-line-strong bg-surface px-2.5 py-1 text-[11px] font-medium text-ink-soft transition hover:border-royal-600 hover:text-royal-800">
                                {{ $showActivity ? 'Hide activity' : 'Show activity' }}
                            </button>
                        </div>

                        @can('createComment', $project)
                            <form wire:submit="addComment" class="flex-none px-4 pb-3">
                                <label for="dr-comment" class="sr-only">Comment</label>
                                <textarea id="dr-comment" wire:model="commentBody" rows="2"
                                          placeholder="Write a comment… type @ and a colleague's name to notify them."
                                          class="w-full rounded-xl border border-line-strong bg-surface px-3 py-2 text-sm transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none"></textarea>
                                @error('commentBody') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                                <div class="mt-1.5 flex items-center justify-between gap-3">
                                    <p class="text-[11px] text-ink-faint">Mentions only match members of this tracker.</p>
                                    <button class="rounded-lg bg-royal-900 px-3.5 py-1.5 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                                        Comment
                                    </button>
                                </div>
                            </form>
                        @endcan

                        {{-- A run of trail entries from one burst of work all say "1 day ago",
                             and ten identical stamps down the rail is ten times the ink for one
                             fact. Only the first of a run is drawn; the rest keep the element,
                             hidden, so the timestamp is still there for a screen reader and the
                             hover title still gives the exact time. Comments reset the run —
                             they are cards, not lines, and group by their own border. --}}
                        @php $lastStamp = null; @endphp

                        <ul class="min-h-0 flex-1 space-y-2.5 overflow-y-auto px-4 pb-4">
                            @forelse ($this->feed as $item)
                                @if ($item['kind'] === 'comment')
                                    @php
                                        $comment = $item['comment'];
                                        $lastStamp = null;
                                    @endphp
                                    <li wire:key="{{ $item['key'] }}" class="rounded-xl border border-line bg-surface px-3 py-2.5">
                                        <div class="flex items-baseline gap-2">
                                            <span class="grid size-5 flex-none place-items-center rounded-full bg-powder-400 text-[9px] font-semibold text-royal-900">
                                                {{ $initials($comment->author?->name ?? '?') }}
                                            </span>
                                            <span class="text-sm font-semibold text-ink">{{ $comment->author?->name ?? 'Unknown' }}</span>
                                            <time class="font-mono text-[11px] text-ink-faint" title="{{ $comment->created_at->toDayDateTimeString() }}">
                                                {{ $comment->created_at->diffForHumans() }}
                                            </time>
                                            @if ($comment->edited_at)
                                                {{-- Visible, because a silently edited comment rewrites a
                                                     conversation others have already replied to. --}}
                                                <span class="font-mono text-[10px] uppercase tracking-wider text-ink-faint">edited</span>
                                            @endif
                                        </div>

                                        @if ($editingCommentId === $comment->id)
                                            <form wire:submit="saveComment" class="mt-2">
                                                <textarea wire:model="editingCommentBody" rows="3" aria-label="Edit comment"
                                                          class="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm"></textarea>
                                                @error('editingCommentBody') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                                                <div class="mt-1.5 flex justify-end gap-2">
                                                    <button type="button" wire:click="cancelEditingComment"
                                                            class="rounded-lg px-3 py-1.5 text-sm text-ink-soft hover:bg-powder-200">Cancel</button>
                                                    <button class="rounded-lg bg-royal-900 px-3.5 py-1.5 text-sm font-medium text-ink-inverse hover:bg-royal-800">Save</button>
                                                </div>
                                            </form>
                                        @else
                                            <p class="mt-1.5 whitespace-pre-wrap text-sm leading-relaxed text-ink-soft">{{ $comment->body }}</p>

                                            <div class="mt-1 flex items-center gap-1">
                                                @can('update', $comment)
                                                    <button wire:click="startEditingComment({{ $comment->id }})"
                                                            class="rounded px-1.5 py-0.5 text-[11px] text-ink-faint hover:bg-powder-200 hover:text-royal-800">Edit</button>
                                                @endcan
                                                @can('delete', $comment)
                                                    <button wire:click="deleteComment({{ $comment->id }})"
                                                            wire:confirm="Delete this comment?"
                                                            class="rounded px-1.5 py-0.5 text-[11px] text-ink-faint hover:bg-health-stalled-bg hover:text-health-stalled">Delete</button>
                                                @endcan
                                            </div>
                                        @endif
                                    </li>
                                @else
                                    @php
                                        $entry = $item['entry'];
                                        $stamp = $entry->created_at->diffForHumans();
                                        $repeated = $stamp === $lastStamp;
                                        $lastStamp = $stamp;
                                    @endphp
                                    <li wire:key="{{ $item['key'] }}" class="flex gap-2 px-1">
                                        <span class="mt-0.5 grid size-5 flex-none place-items-center rounded-full bg-canvas-sunken text-[9px] font-semibold text-ink-soft">
                                            {{ $entry->user ? $initials($entry->user->name) : '·' }}
                                        </span>
                                        <p class="min-w-0 flex-1 text-[13px] leading-snug text-ink-soft">
                                            <span class="font-medium text-ink">{{ $entry->user?->name ?? 'System' }}</span>
                                            {{ $entry->describe() }}
                                            <time class="{{ $repeated ? 'sr-only' : 'block' }} font-mono text-[11px] text-ink-faint"
                                                  title="{{ $entry->created_at->toDayDateTimeString() }}">
                                                {{ $stamp }}
                                            </time>
                                        </p>
                                    </li>
                                @endif
                            @empty
                                <li class="rounded-lg border border-dashed border-line-strong px-3 py-6 text-center text-sm text-ink-faint">
                                    Nothing here yet.
                                </li>
                            @endforelse
                        </ul>
                    </section>
                </div>
            </aside>
        </div>
    @endif
</div>
