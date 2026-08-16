<x-layouts.app title="Settings · Trackers · Worktrack">
    {{-- Settings runs on the same chrome as the rest of the app. It used to have none,
         which made configuration feel like a separate tool bolted on the side. --}}
    <x-app-nav current="settings" eyebrow="Settings" />

    <div class="mx-auto max-w-6xl px-5 py-6">

        <h1 class="text-lg font-semibold tracking-tight">Settings</h1>

        <x-settings-tabs current="trackers" />

        <div class="mt-6">
            <h2 class="text-base font-semibold tracking-tight">Trackers</h2>
            <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                Each tracker has its own steps and its own members. People only see the ones they belong to.
            </p>
        </div>

        @if (session('status'))
            <p class="mt-6 rounded-lg bg-health-ontrack-bg px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif
        @error('member')
            <p class="mt-6 rounded-lg bg-health-stalled-bg px-4 py-3 text-sm text-red-900">{{ $message }}</p>
        @enderror

        {{-- create --}}
        <form method="POST" action="{{ route('admin.trackers.store') }}"
              class="mt-7 rounded-xl border border-line bg-surface p-5 shadow-sm">
            @csrf
            <h2 class="text-sm font-semibold">New tracker</h2>
            <p class="mt-1 text-xs text-ink-soft">
                Comes with Backlog, New, In Progress and Done so it works immediately.
            </p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-[220px] flex-1">
                    <label for="name" class="block text-xs font-medium">Name</label>
                    <input id="name" name="name" required maxlength="120" value="{{ old('name') }}"
                           placeholder="IT Technical"
                           class="mt-1 w-full rounded-lg border border-line-strong px-3 py-2 text-sm">
                    @error('name')<p class="mt-1 text-xs text-health-stalled">{{ $message }}</p>@enderror
                </div>
                <div class="min-w-[220px] flex-[2]">
                    <label for="description" class="block text-xs font-medium">Description <span class="text-ink-faint">(optional)</span></label>
                    <input id="description" name="description" maxlength="1000" value="{{ old('description') }}"
                           placeholder="Networking, infrastructure and support"
                           class="mt-1 w-full rounded-lg border border-line-strong px-3 py-2 text-sm">
                </div>
                <div class="w-36">
                    <label for="stall" class="block text-xs font-medium">Stall after</label>
                    <input id="stall" name="stall_threshold_days" type="number" min="1" max="365"
                           placeholder="{{ config('worktrack.stall.threshold_days') }} days"
                           class="mt-1 w-full rounded-lg border border-line-strong px-3 py-2 text-sm">
                </div>
                <button class="rounded-lg bg-royal-900 px-4 py-2 text-sm font-medium text-white hover:bg-royal-800">
                    Create
                </button>
            </div>
        </form>

        {{-- list --}}
        <div class="mt-6 space-y-4">
            @forelse ($trackers as $tracker)
                <div @class(['rounded-xl border bg-surface p-5 shadow-sm', 'border-line' => !$tracker->archived_at, 'border-line opacity-60' => $tracker->archived_at])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold">
                                {{ $tracker->name }}
                                @if ($tracker->archived_at)
                                    <span class="ml-1 rounded bg-canvas-sunken px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-ink-soft">archived</span>
                                @endif
                            </h3>
                            @if ($tracker->description)
                                <p class="mt-0.5 text-xs text-ink-soft">{{ $tracker->description }}</p>
                            @endif
                            <p class="mt-1 font-mono text-[11px] text-ink-faint">
                                {{ $tracker->projects_count }} projects · {{ $tracker->members_count }} members
                                @if ($tracker->stall_threshold_days) · stalls after {{ $tracker->stall_threshold_days }}d @endif
                            </p>
                        </div>

                        @unless ($tracker->archived_at)
                            <form method="POST" action="{{ route('admin.trackers.archive', $tracker) }}"
                                  onsubmit="return confirm('Archive {{ $tracker->name }}? Its projects stay in reports but disappear from the board.')">
                                @csrf
                                <button class="rounded-lg border border-line-strong px-3 py-1.5 text-xs font-medium hover:bg-canvas">
                                    Archive
                                </button>
                            </form>
                        @endunless
                    </div>

                    @unless ($tracker->archived_at)
                        {{-- FR-3.1 — steps are per-tracker, so they are configured here
                             rather than in any global settings screen. --}}
                        <div class="mt-4 border-t border-line-soft pt-4">
                            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Steps</p>

                            {{-- The same three phrases the board legend uses, because they
                                 describe the same three types and a second wording would
                                 read as a fourth concept. --}}
                            @php
                                $typeMeans = [
                                    'intake' => 'waiting, not working',
                                    'active' => 'counts toward cycle time',
                                    'terminal' => 'stops the clock',
                                ];
                            @endphp

                            {{-- Left to right is the workflow order the board draws. --}}
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                @foreach ($tracker->steps as $step)
                                    @php
                                        $dot = match ($step->type->value) {
                                            'intake' => 'bg-step-intake',
                                            'active' => 'bg-step-active',
                                            'terminal' => 'bg-step-terminal',
                                        };
                                    @endphp
                                    <span class="flex items-center gap-1 rounded-full border border-line bg-canvas py-1 pl-1 pr-1.5 text-xs">
                                        {{-- FR-3.1 — the dot IS the type control.
                                             The dot already means "step type" here and on the board,
                                             so making it the thing you change keeps one symbol for
                                             one concept instead of adding a second widget that says
                                             the same word twice. Submits on change: the chip row has
                                             no save button, same as rename and the due gate.

                                             Sized to match the move and gate buttons beside it — the
                                             dot stays 6px because that is what the legend shows, but
                                             the target around it is the row's standard 16px.

                                             This is the one step edit that changes how work is
                                             MEASURED, hence the blunt title text: an admin should
                                             learn what it costs before choosing, not from the status
                                             message afterwards. --}}
                                        <form method="POST" action="{{ route('admin.trackers.steps.retype', [$tracker, $step]) }}"
                                              class="relative grid size-4 place-items-center rounded hover:bg-powder-200 focus-within:ring-2 focus-within:ring-royal-600">
                                            @csrf
                                            <i class="pointer-events-none size-1.5 rounded-full {{ $dot }}"></i>
                                            {{-- requestSubmit, not submit, matching the role picker on
                                                 the users screen: submit() skips validation and the
                                                 submit event entirely, which is a trap the moment
                                                 anything is ever added to this form. --}}
                                            <select name="type" onchange="this.form.requestSubmit()"
                                                    aria-label="Step type for {{ $step->name }}"
                                                    title="{{ $step->name }} is {{ $step->type->value }} — {{ $typeMeans[$step->type->value] }}. Changing it changes how future work here is measured; finished work keeps the cycle time it was already measured with."
                                                    class="absolute inset-0 size-full cursor-pointer appearance-none bg-transparent opacity-0">
                                                @foreach ($typeMeans as $value => $means)
                                                    <option value="{{ $value }}" @selected($step->type->value === $value)>{{ $value }} — {{ $means }}</option>
                                                @endforeach
                                            </select>
                                            <noscript><button class="text-[10px] underline">set</button></noscript>
                                        </form>

                                        {{-- FR-3.1 — the name IS the field. A rename is one short word,
                                             so a separate edit screen would be more ceremony than the
                                             change deserves. Reads as plain text until hovered, Enter
                                             saves, and the tick appears on focus for anyone who wants a
                                             button. Not repopulated from old() on purpose: `name` is a
                                             page-global old-input key shared with every other step and
                                             the add-step form, so echoing it back would spray one
                                             rejected value across the whole screen. --}}
                                        @php $renameErrors = $errors->getBag('step_rename_'.$step->id); @endphp
                                        <form method="POST" action="{{ route('admin.trackers.steps.rename', [$tracker, $step]) }}"
                                              class="group/rename flex items-center">
                                            @csrf
                                            {{-- size= is the fallback width for browsers without
                                                 field-sizing; where it is supported the input grows
                                                 with what is typed. --}}
                                            <input name="name" required maxlength="80"
                                                   value="{{ $step->name }}"
                                                   size="{{ max(4, mb_strlen($step->name)) }}"
                                                   aria-label="Rename {{ $step->name }}"
                                                   @class([
                                                       'field-sizing-content min-w-10 rounded border bg-transparent px-1 py-0.5 text-xs',
                                                       'hover:border-line-strong focus:border-sky-600 focus:bg-surface focus:outline-none',
                                                       'border-health-stalled' => $renameErrors->isNotEmpty(),
                                                       'border-transparent' => $renameErrors->isEmpty(),
                                                   ])>
                                            <button title="Save name for {{ $step->name }}"
                                                    class="grid size-4 place-items-center rounded text-ink-faint opacity-0 transition hover:bg-powder-200 hover:text-ink group-focus-within/rename:opacity-100">
                                                ✓
                                            </button>
                                        </form>

                                        @if ($step->wip_limit)
                                            <span class="font-mono text-[10px] text-ink-faint">wip {{ $step->wip_limit }}</span>
                                        @endif

                                        {{-- The due-date gate. A toggle rather than a checkbox in a
                                             settings panel: the chip row has no save button, so the
                                             control has to be the whole interaction. Filled when on,
                                             because "this column blocks drops" is the kind of fact
                                             that has to be readable without hovering anything. --}}
                                        <form method="POST" action="{{ route('admin.trackers.steps.gate', [$tracker, $step]) }}">
                                            @csrf
                                            <input type="hidden" name="requires_due_date" value="{{ $step->requires_due_date ? 0 : 1 }}">
                                            <button
                                                title="{{ $step->requires_due_date
                                                    ? 'Moving a project into '.$step->name.' requires a due date. Click to stop requiring it.'
                                                    : 'Require a due date before a project can be moved into '.$step->name.'.' }}"
                                                aria-pressed="{{ $step->requires_due_date ? 'true' : 'false' }}"
                                                @class([
                                                    'ml-0.5 grid h-4 place-items-center rounded px-1 font-mono text-[10px] transition',
                                                    'bg-royal-900 text-ink-inverse' => $step->requires_due_date,
                                                    'text-ink-faint hover:bg-powder-200 hover:text-ink' => ! $step->requires_due_date,
                                                ])>
                                                due
                                            </button>
                                        </form>

                                        {{-- One place at a time, in plain forms. The ends are
                                             disabled rather than hidden so the controls do not
                                             shift position as steps move. --}}
                                        @php
                                            // Captured from the OUTER loop before the inner one
                                            // shadows $loop.
                                            $controls = [
                                                'up' => ['←', 'earlier', $loop->first],
                                                'down' => ['→', 'later', $loop->last],
                                            ];
                                        @endphp
                                        <span class="ml-0.5 flex items-center">
                                            @foreach ($controls as $direction => [$glyph, $label, $atEnd])
                                                <form method="POST" action="{{ route('admin.trackers.steps.move', [$tracker, $step]) }}">
                                                    @csrf
                                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                                    <button @disabled($atEnd)
                                                            title="Move {{ $step->name }} {{ $label }}"
                                                            class="grid size-4 place-items-center rounded text-ink-faint hover:bg-powder-200 hover:text-ink disabled:pointer-events-none disabled:opacity-25">
                                                        {{ $glyph }}
                                                    </button>
                                                </form>
                                            @endforeach
                                        </span>
                                    </span>
                                @endforeach
                            </div>

                            {{-- Named, because the input reverted to the stored name the moment
                                 the page re-rendered and the message is the only thing left
                                 explaining why. --}}
                            @foreach ($tracker->steps as $step)
                                @foreach ($errors->getBag('step_rename_'.$step->id)->all() as $message)
                                    <p class="mt-2 text-xs text-health-stalled">{{ $step->name }}: {{ $message }}</p>
                                @endforeach

                                {{-- FR-3.3. Needs saying at least as loudly as a rename
                                     collision: the select has already snapped back to the
                                     stored type, so without this the refusal is invisible
                                     and reads as a control that does nothing. --}}
                                @foreach ($errors->getBag('step_type_'.$step->id)->all() as $message)
                                    <p class="mt-2 text-xs text-health-stalled">{{ $message }}</p>
                                @endforeach
                            @endforeach

                            {{-- Said out loud because it is the one non-obvious consequence:
                                 ProjectService::create drops new work into the first step. --}}
                            <p class="mt-2 text-[11px] text-ink-faint">
                                New projects enter at <span class="font-medium">{{ $tracker->steps->first()?->name ?? '—' }}</span>,
                                the first step. Reordering changes that. Metrics group by step type, not order.
                            </p>
                            {{-- Said plainly because it is the only hard block on a drop in the
                                 product, and an admin switching it on should know that before a
                                 colleague meets the dialog. --}}
                            <p class="mt-1 text-[11px] text-ink-faint">
                                <span class="font-mono">due</span> marks a step that cannot be entered until the project has a due date.
                                Dragging an undated card there asks for one; cancelling leaves the card where it was.
                            </p>

                            <form method="POST" action="{{ route('admin.trackers.steps.store', $tracker) }}"
                                  class="mt-3 flex flex-wrap items-end gap-2">
                                @csrf
                                <div>
                                    <label for="step-name-{{ $tracker->id }}" class="block text-[11px] font-medium">Name</label>
                                    <input id="step-name-{{ $tracker->id }}" name="name" required maxlength="80"
                                           placeholder="Testing"
                                           class="mt-1 w-40 rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                </div>

                                {{-- FR-3.2: the type is what every metric groups by, so it is a
                                     required choice, not a default someone can leave wrong. --}}
                                <div>
                                    <label for="step-type-{{ $tracker->id }}" class="block text-[11px] font-medium">Type</label>
                                    <select id="step-type-{{ $tracker->id }}" name="type" required
                                            class="mt-1 rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                        <option value="intake">Intake — waiting, not working</option>
                                        <option value="active" selected>Active — counts toward cycle time</option>
                                        <option value="terminal">Terminal — stops the clock</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="step-after-{{ $tracker->id }}" class="block text-[11px] font-medium">Place after</label>
                                    <select id="step-after-{{ $tracker->id }}" name="after_step_id"
                                            class="mt-1 rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                        <option value="">First column</option>
                                        @foreach ($tracker->steps as $step)
                                            <option value="{{ $step->id }}" @selected($loop->last)>{{ $step->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="step-wip-{{ $tracker->id }}" class="block text-[11px] font-medium">WIP limit</label>
                                    <input id="step-wip-{{ $tracker->id }}" name="wip_limit" type="number" min="1" max="999"
                                           placeholder="none"
                                           class="mt-1 w-24 rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                </div>

                                {{-- The gate, at creation time. Same rule the "due" toggle on each
                                     chip flips afterwards — offered here too so a column that is
                                     meant to be a commitment point does not spend its first day
                                     letting undated work through. --}}
                                <label class="flex items-center gap-1.5 pb-1.5 text-[11px] font-medium">
                                    <input name="requires_due_date" type="checkbox" value="1"
                                           class="size-3.5 rounded border-line-strong text-royal-900 focus:ring-royal-600/20">
                                    Require a due date
                                </label>

                                <button class="rounded-lg border border-line-strong px-3 py-1.5 text-xs font-medium hover:bg-canvas">
                                    Add step
                                </button>
                            </form>

                            @foreach ($errors->getBag('step_'.$tracker->id)->all() as $message)
                                <p class="mt-2 text-xs text-health-stalled">{{ $message }}</p>
                            @endforeach
                        </div>

                        <div class="mt-4 border-t border-line-soft pt-4">
                            <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Members</p>

                            <div class="mt-2 flex flex-wrap gap-2">
                                @forelse ($tracker->members as $member)
                                    <form method="POST" action="{{ route('admin.trackers.members.remove', [$tracker, $member->user_id]) }}"
                                          class="flex items-center gap-1.5 rounded-full border border-line bg-canvas py-1 pl-3 pr-1.5">
                                        @csrf
                                        @method('DELETE')
                                        <span class="text-xs">{{ $member->user->name }}</span>
                                        <span class="font-mono text-[10px] text-ink-faint">{{ $member->user->role->value }}</span>
                                        <button title="Remove from tracker"
                                                class="grid size-4 place-items-center rounded-full text-ink-faint hover:bg-powder-200 hover:text-ink">×</button>
                                    </form>
                                @empty
                                    <p class="text-xs text-ink-faint">Nobody yet — this tracker is invisible to everyone but admins.</p>
                                @endforelse
                            </div>

                            <form method="POST" action="{{ route('admin.trackers.members.add', $tracker) }}"
                                  class="mt-3 flex items-center gap-2">
                                @csrf
                                <select name="user_id" required class="rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                    <option value="">Add an approved user…</option>
                                    @foreach ($approvedUsers as $user)
                                        @unless ($tracker->members->contains('user_id', $user->id))
                                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->role->value }})</option>
                                        @endunless
                                    @endforeach
                                </select>
                                <button class="rounded-lg border border-line-strong px-3 py-1.5 text-xs font-medium hover:bg-canvas">Add</button>
                            </form>
                        </div>
                    @endunless
                </div>
            @empty
                <p class="rounded-xl border border-line bg-surface p-10 text-center text-sm text-ink-faint">
                    No trackers yet. Create the first one above.
                </p>
            @endforelse
        </div>

        <x-app-footer class="mt-6">
            <span class="max-w-2xl normal-case tracking-normal">
                Removing someone clears their project and task assignments in that tracker immediately —
                you cannot hold work in a tracker you can no longer see. Their history stays attributed to them.
            </span>
        </x-app-footer>
    </div>
</x-layouts.app>
