{{--
    The board. Columns are the tracker's steps; cards are projects.

    Drag-and-drop is SortableJS driven by Alpine, inside wire:ignore.self so Livewire's
    DOM diffing does not fight the drag. The reorder happens client-side first (NFR-P3
    wants it instant), then the server is told. A refused move re-renders from the
    database, which snaps the card back to where it actually is.

    COLOUR: chrome uses brand tokens (royal / powder / bone); health flags and step
    types use the semantic tokens. The two families are deliberately far apart on the
    wheel so a coloured chip on a card always means status, never decoration.
--}}
<div class="flex h-full min-h-screen flex-col bg-canvas">

    {{-- ── header ──────────────────────────────────────────────────────────────
         Royal, and the only saturated band on the screen. Everything below it is
         bone and white, so the eye starts here and then goes straight to the
         status colours rather than competing with the chrome.

         The eyebrow names the page, as it does everywhere else. Which tracker you
         are looking at is the select's job, right beside it. --}}
    <x-app-nav current="board" eyebrow="Board">
        @if ($this->trackers->isNotEmpty())
            {{-- Sits on royal-800 rather than white: a white control here would read
                 as the page starting inside the header. --}}
            <select wire:change="switchTracker($event.target.value)"
                    aria-label="Switch tracker"
                    class="rounded-lg border border-royal-700 bg-royal-800 px-2.5 py-1.5 text-sm text-ink-inverse focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-powder-300">
                @foreach ($this->trackers as $t)
                    <option value="{{ $t->public_id }}" @selected($t->public_id === $trackerId)>{{ $t->name }}</option>
                @endforeach
            </select>
        @endif
    </x-app-nav>

    @if (! $this->tracker)
        {{-- FR-1.10 / FR-2.6 — an approved user with no trackers must get an
             explanation, not a blank screen. The empty state differs by role because
             an admin can fix it themselves and a member cannot. --}}
        <div class="mx-auto max-w-lg px-6 py-24 text-center">
            <p class="text-base font-semibold text-ink">No trackers yet</p>
            @can('tracker.create')
                <p class="mt-2 text-sm text-ink-soft">
                    Create one to start logging work. It comes with Backlog, New, In Progress
                    and Done, which you can rename afterwards.
                </p>
                <a href="{{ route('admin.trackers.index') }}"
                   class="mt-5 inline-block rounded-lg bg-royal-900 px-4 py-2 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                    Create a tracker
                </a>
            @else
                <p class="mt-2 text-sm text-ink-soft">
                    You're approved, but an administrator hasn't added you to a tracker yet.
                    You'll see work here as soon as they do.
                </p>
            @endcan
        </div>
    @else
        {{-- min-h-0 lets the column strip below own the leftover height instead of
             the board trailing off into empty page. --}}
        <div class="flex min-h-0 flex-1 flex-col px-5 py-4">
            <div class="flex flex-none flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold tracking-tight text-ink">{{ $this->tracker->name }}</h1>
                    @if ($this->tracker->description)
                        <p class="mt-0.5 text-sm text-ink-soft">{{ $this->tracker->description }}</p>
                    @endif
                </div>

                @can('project.create')
                    {{-- The one primary action on this screen, so it is the only royal
                         fill below the header. Everything else is a quiet control. --}}
                    <button wire:click="newProject"
                            class="rounded-lg bg-royal-900 px-3.5 py-2 text-sm font-medium text-ink-inverse shadow-sm transition hover:bg-royal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-700">
                        + Add project
                    </button>
                @endcan
            </div>

            {{-- ── filters (FR-3.6) ─────────────────────────────────────────────
                 One wrapping row of quiet controls rather than a panel behind a
                 button: on a board, "what am I looking at" has to be readable without
                 opening anything, or a filtered board becomes a board someone else
                 mistakes for the whole picture.

                 Every control writes to the query string, so the answer to "show me
                 what you mean" is a pasted URL. --}}
            <div class="mt-3 flex flex-none flex-wrap items-center gap-2">
                <div class="relative">
                    <input wire:model.live.debounce.300ms="search"
                           type="search"
                           aria-label="Search cards by name or description"
                           placeholder="Search cards…"
                           class="w-56 rounded-lg border border-line-strong bg-surface py-1.5 pl-8 pr-2.5 text-sm placeholder:text-ink-faint focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-royal-600">
                    <span aria-hidden="true" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-ink-faint">⌕</span>
                </div>

                {{-- Each of these takes a LIST. "What are Ana and Dennis on" and "what
                     is at risk or stalled" are the questions people actually arrive
                     with, and a one-value control answers them by making you look
                     twice and hold the union in your head. --}}
                <x-filter-menu title="Filter by health"
                               empty="Any health"
                               noun="health flags"
                               model="healthStates"
                               :options="$this->healthOptions"
                               :selected="$this->selected['health']" />

                <x-filter-menu title="Filter by assignee"
                               empty="Anyone assigned"
                               noun="assignees"
                               model="assignees"
                               :options="$this->filterPeople->pluck('name', 'id')"
                               :selected="$this->selected['assignees']" />

                <x-filter-menu title="Filter by owner"
                               empty="Any owner"
                               noun="owners"
                               model="owners"
                               :options="$this->filterPeople->pluck('name', 'id')"
                               :selected="$this->selected['owners']" />

                @if ($this->departments->isNotEmpty())
                    <x-filter-menu title="Filter by department"
                                   empty="Any department"
                                   noun="departments"
                                   model="departmentIds"
                                   :options="$this->departments->pluck('name', 'id')"
                                   :selected="$this->selected['departments']" />
                @endif

                <div class="flex items-center gap-1.5 rounded-lg border border-line-strong bg-surface px-2.5 py-1">
                    <label for="board-due-from" class="text-[11px] font-medium text-ink-faint">Due</label>
                    <input id="board-due-from" wire:model.live="dueFrom" type="date" aria-label="Due on or after"
                           class="w-32 border-0 bg-transparent p-0 text-sm focus:outline-none">
                    <span aria-hidden="true" class="text-ink-faint">→</span>
                    <input wire:model.live="dueTo" type="date" aria-label="Due on or before"
                           class="w-32 border-0 bg-transparent p-0 text-sm focus:outline-none">
                </div>

                @if ($this->hasFilters)
                    <span class="tnum font-mono text-[11px] text-ink-faint">
                        {{ $this->shownCards }} of {{ $this->totalCards }}
                    </span>

                    <button wire:click="clearFilters"
                            class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-royal-800 underline-offset-2 transition hover:bg-powder-200 hover:underline">
                        Clear filters
                    </button>
                @endif
            </div>

            {{-- Every column can legitimately be empty, so an empty BOARD under active
                 filters needs saying out loud — otherwise a filter left on from
                 yesterday reads as "there is no work". --}}
            @if ($this->hasFilters && $this->shownCards === 0 && $this->totalCards > 0)
                <p class="mt-3 flex-none rounded-lg bg-powder-100 px-3 py-2 text-sm text-ink-soft" role="status">
                    None of the {{ $this->totalCards }} cards on this board match these filters.
                    <button wire:click="clearFilters" class="font-medium text-royal-800 underline underline-offset-2">Clear them</button>
                    to see everything.
                </p>
            @endif

            {{-- ── columns ─────────────────────────────────────────────────── --}}
            <div class="mt-4 flex min-h-0 flex-1 items-stretch gap-3 overflow-x-auto pb-2"
                 x-data
                 wire:ignore.self>
                @foreach ($this->steps as $step)
                    @php
                        $cards = $this->projects[$step->id] ?? collect();
                        $dot = match ($step->type->value) {
                            'intake' => 'bg-step-intake',
                            'active' => 'bg-step-active',
                            'terminal' => 'bg-step-terminal',
                        };

                        // FR-3.8 — a visual warning, never a hard block on drop.
                        $overWip = $step->wip_limit && $cards->count() > $step->wip_limit;
                    @endphp

                    {{-- Columns share the width instead of huddling at the left on a wide
                         monitor, but stop growing before cards get uncomfortably long to read. --}}
                    <section class="flex min-w-62 max-w-95 flex-1 basis-67 flex-col rounded-xl bg-canvas-sunken p-2 ring-1 ring-line">
                        <div class="mb-1.5 flex flex-none items-center justify-between px-1">
                            <span class="flex items-center gap-2 text-sm font-semibold text-ink">
                                <i class="size-1.5 rounded-full {{ $dot }}"></i>{{ $step->name }}

                                {{-- Marks a column that will refuse an undated card. Shown
                                     because the alternative is learning the rule by being
                                     stopped by it: a gate you can see before you drag is a
                                     rule, one you meet afterwards is an error. --}}
                                @if ($step->requires_due_date)
                                    <span class="rounded bg-canvas px-1 font-mono text-[9px] font-medium uppercase tracking-[0.06em] text-ink-faint ring-1 ring-line"
                                          title="A project needs a due date before it can be moved into {{ $step->name }}.">
                                        due
                                    </span>
                                @endif
                            </span>
                            <span class="tnum font-mono text-[11px] {{ $overWip ? 'font-semibold text-health-atrisk' : 'text-ink-faint' }}"
                                  @if ($step->wip_limit) title="{{ $overWip ? 'Over' : 'Within' }} the WIP limit of {{ $step->wip_limit }}" @endif>
                                {{ $cards->count() }}@if ($step->wip_limit)/{{ $step->wip_limit }}@endif
                            </span>
                        </div>

                        {{-- wire:ignore.self, NOT wire:ignore.

                             SortableJS owns this element — Alpine initialises it once and
                             Livewire must not re-morph the element itself mid-drag. But a
                             full wire:ignore also freezes the CARDS inside it, which meant
                             a newly created project never appeared until a page reload:
                             the server had it, the board silently did not.

                             .self keeps Sortable's element untouched while letting card
                             rows re-render from the database. Each card carries a wire:key
                             so morphing matches cards by identity rather than by position,
                             which is what stops a re-render after a drag from swapping two
                             cards' contents. --}}
                        <div wire:ignore.self
                             x-data="boardColumn({{ $step->id }})"
                             x-init="init()"
                             data-step-id="{{ $step->id }}"
                             class="min-h-15 flex-1 space-y-1.5 overflow-y-auto">
                            @foreach ($cards as $project)
                                @php
                                    // Health tone. Each pair is a token, so the flag colours
                                    // are defined once in app.css and cannot drift between
                                    // the board, the drawer and the dashboard.
                                    $tone = match ($project->health->value) {
                                        'on_track' => 'bg-health-ontrack-bg text-health-ontrack',
                                        'at_risk' => 'bg-health-atrisk-bg text-health-atrisk',
                                        'stalled' => 'bg-health-stalled-bg text-health-stalled',
                                        'on_hold' => 'bg-health-onhold-bg text-health-onhold',
                                    };
                                    // Priority tone, same token pairs as the health flags and the
                                    // drawer's priority chip. Urgent and high borrow the at-risk /
                                    // stalled colours on purpose: on a board, "urgent" and "behind"
                                    // want the same glance. Normal and low stay on the neutral
                                    // sunken chip so the two that need attention are the two that
                                    // carry colour.
                                    $priorityTone = match ($project->priority) {
                                        'urgent' => 'bg-health-stalled-bg text-health-stalled',
                                        'high' => 'bg-health-atrisk-bg text-health-atrisk',
                                        'low' => 'bg-canvas-sunken text-ink-faint',
                                        default => 'bg-canvas-sunken text-ink-soft',
                                    };
                                    $days = (int) $project->current_step_entered_at->diffInDays(now());

                                    $overdue = $project->isOverdue();

                                    $initials = fn ($name) => \Illuminate\Support\Str::of($name)
                                        ->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
                                @endphp

                                {{-- The whole card opens the card, as on any board people have
                                     used before. The card is ALSO the drag handle, so a naive
                                     click handler fires on every aborted drag; the guard below
                                     is what makes both behaviours live on one element.

                                     Two conditions, both necessary. A click that started more
                                     than a few pixels from where it ended was a drag, not a
                                     click — SortableJS re-fires a click on the item it dropped,
                                     and without the distance test every drop would also open
                                     the drawer. And a click that came from a control inside the
                                     card belongs to that control: Edit, Open and the drawer's
                                     own buttons all bubble to here otherwise, so Edit would
                                     open the dialog AND the drawer behind it. --}}
                                <article wire:key="card-{{ $project->public_id }}"
                                         data-project="{{ $project->public_id }}"
                                         data-id="{{ $project->id }}"
                                         x-data="{ downX: 0, downY: 0 }"
                                         x-on:pointerdown="downX = $event.clientX; downY = $event.clientY"
                                         x-on:click="
                                             if ($event.target.closest('button, a, input, select, textarea, label')) return;
                                             if (Math.hypot($event.clientX - downX, $event.clientY - downY) > 6) return;
                                             $wire.openProject('{{ $project->public_id }}')
                                         "
                                         class="group cursor-grab rounded-lg border border-line bg-surface px-2.5 py-2 shadow-xs transition hover:border-powder-400 hover:shadow-sm active:cursor-grabbing">

                                    <div class="flex items-start gap-1.5">
                                        {{-- The title stays a button. Pointer users get the whole
                                             card, but a pointer guard is unreachable by keyboard,
                                             and this is what puts the card in the tab order at
                                             all. Same action, two ways in. --}}
                                        <button wire:click="openProject('{{ $project->public_id }}')"
                                                class="flex-1 rounded text-left text-[13px] font-semibold leading-snug text-ink hover:text-royal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-600">
                                            {{ $project->name }}
                                        </button>

                                        {{-- A visible affordance for the click target the card
                                             now is: without it, "the card opens" is something
                                             you find by accident or not at all. --}}
                                        <button wire:click="openProject('{{ $project->public_id }}')"
                                                class="-mt-0.5 rounded p-1 text-ink-faint opacity-0 transition hover:bg-powder-200 hover:text-royal-800 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-royal-600 group-hover:opacity-100"
                                                aria-label="Open {{ $project->name }}">
                                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"
                                                 stroke-linecap="round" stroke-linejoin="round" class="size-3.5" aria-hidden="true">
                                                <path d="M6 2H2v4M10 14h4v-4M2 2l5 5M14 14l-5-5" />
                                            </svg>
                                        </button>

                                        @can('update', $project)
                                            <button wire:click="editProject('{{ $project->public_id }}')"
                                                    class="-mr-1 -mt-0.5 rounded px-1.5 py-0.5 text-[11px] font-medium text-ink-faint opacity-0 transition hover:bg-powder-200 hover:text-royal-800 focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-royal-600 group-hover:opacity-100"
                                                    aria-label="Edit {{ $project->name }}">Edit</button>
                                        @endcan
                                    </div>

                                    @if ($project->tags->isNotEmpty())
                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            {{-- The one place a raw colour is legitimate: it is
                                                 user data stored per tag, not a design decision. --}}
                                            @foreach ($project->tags as $tag)
                                                <span class="rounded px-1.5 py-px text-[10px] font-medium text-white"
                                                      style="background-color: {{ $tag->color ?? '#55607d' }}">{{ $tag->name }}</span>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Health, priority, progress and age share one line: four glances
                                         at a card, not four rows of it, so more of the column fits on
                                         screen. gap-x/gap-y because the pair of chips wraps before the
                                         counts do in a narrow column. --}}
                                    <div class="tnum mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-[11px] text-ink-faint">
                                        {{-- The word is part of the flag, not a tooltip: colour
                                             alone must never be the carrier of a state. --}}
                                        <span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider {{ $tone }}">
                                            {{ str_replace('_', ' ', $project->health->value) }}
                                        </span>

                                        {{-- Priority reads next to health, never alone: "at risk" and
                                             "urgent" answer different questions, and the pair is what
                                             tells you which card to pick up first. Shown on every card,
                                             including normal — a chip that only appears when it is high
                                             makes its absence ambiguous, since you cannot tell a normal
                                             card from one nobody has triaged. --}}
                                        <span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider {{ $priorityTone }}"
                                              title="Priority: {{ $project->priority }}">
                                            {{ $project->priority }}
                                        </span>
                                        @if ($project->tasks_total)
                                            <span title="{{ $project->tasks_done }} of {{ $project->tasks_total }} tasks done">
                                                {{ $project->tasks_done }}/{{ $project->tasks_total }}
                                            </span>
                                        @endif
                                        @if ($project->attachments_count)
                                            <span title="{{ $project->attachments_count }} attachments">{{ $project->attachments_count }}f</span>
                                        @endif
                                        {{-- The number the whole product exists to surface. --}}
                                        <span class="ml-auto {{ $days > 20 ? 'font-semibold text-health-stalled' : 'text-ink-soft' }}"
                                              title="{{ $days }} days in this step">
                                            {{ $days }}d
                                        </span>
                                    </div>

                                    @if ($project->owner || $project->assignees->isNotEmpty() || $project->target_date)
                                        <div class="mt-1.5 flex items-center gap-2 text-[11px] text-ink-faint">
                                            {{-- Initials, not avatars: no image to fetch per card, and
                                                 the full name is one hover away. Royal for the owner,
                                                 powder for assignees — accountable vs doing. --}}
                                            @if ($project->owner)
                                                <span title="Owner: {{ $project->owner->name }}"
                                                      class="grid size-5 flex-none place-items-center rounded-full bg-royal-900 text-[9px] font-semibold text-ink-inverse">
                                                    {{ $initials($project->owner->name) }}
                                                </span>
                                            @endif

                                            @foreach ($project->assignees->take(3) as $assignee)
                                                <span title="Assigned: {{ $assignee->name }}"
                                                      class="-ml-3.5 grid size-5 flex-none place-items-center rounded-full border border-surface bg-powder-400 text-[9px] font-semibold text-royal-900">
                                                    {{ $initials($assignee->name) }}
                                                </span>
                                            @endforeach

                                            @if ($project->assignees->count() > 3)
                                                <span class="-ml-3.5 grid size-5 flex-none place-items-center rounded-full border border-surface bg-powder-200 text-[9px] font-semibold text-royal-700">
                                                    +{{ $project->assignees->count() - 3 }}
                                                </span>
                                            @endif

                                            @if ($project->target_date)
                                                <span class="tnum ml-auto font-mono {{ $overdue ? 'font-semibold text-health-stalled' : 'text-ink-faint' }}"
                                                      title="Due {{ $project->target_date->toFormattedDateString() }}">
                                                    {{ $overdue ? 'overdue · ' : 'due ' }}{{ $project->target_date->format('j M') }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </article>
                            @endforeach

                            @if ($cards->isEmpty())
                                {{-- An empty column should look like a place a card can go,
                                     not like the board failed to load. --}}
                                <p class="rounded-lg border border-dashed border-line-strong px-2.5 py-3 text-center text-[11px] text-ink-faint">
                                    Nothing here
                                </p>
                            @endif
                        </div>
                    </section>
                @endforeach

            </div>

            {{-- ── footer / legend ──────────────────────────────────────────
                 The key to the column dots, which is the only place the step
                 semantics (FR-3.2) are explained in the UI. --}}
            <x-app-footer class="mt-2 flex-none">
                <span class="flex items-center gap-1.5">
                    <i class="inline-block size-1.5 rounded-full bg-step-intake"></i>waiting
                </span>
                <span class="flex items-center gap-1.5">
                    <i class="inline-block size-1.5 rounded-full bg-step-active"></i>counts toward cycle time
                </span>
                <span class="flex items-center gap-1.5">
                    <i class="inline-block size-1.5 rounded-full bg-step-terminal"></i>stops the clock
                </span>
            </x-app-footer>
        </div>
    @endif

    {{-- Both render nothing until opened, so a closed dialog's inputs are absent from
         the DOM entirely rather than merely hidden. --}}
    <livewire:project-modal />

    {{-- FR-8.11 — a ?project= link from the dashboard opens the drawer on first paint.
         Passed as a mount prop rather than a dispatched event: an event fired from the
         parent's mount races the child's own mount, and losing that race is a link
         that silently does nothing. --}}
    <livewire:project-drawer :project="$openProjectId" />

    {{-- Opened by a refused drop, not by any button on this page. --}}
    <livewire:due-date-prompt />
</div>
