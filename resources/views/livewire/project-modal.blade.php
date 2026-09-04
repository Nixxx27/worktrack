{{--
    Add / edit a project.

    Rendered as a real overlay only while $open, so the form's inputs are absent from
    the DOM the rest of the time — a closed modal cannot be tab-focused, and a screen
    reader is not walked through a form nobody opened.

    Alpine owns only the trap-and-dismiss behaviour. Every field is wire:model, so the
    server is the single source of truth for what is in the form; a refused save
    re-renders with the values still in it rather than emptying the dialog.
--}}
<div>
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-royal-900/40 p-4 sm:p-8"
             x-data
             x-on:keydown.escape.window="$wire.close()"
             x-on:click.self="$wire.close()">

            <div class="w-full max-w-2xl rounded-2xl bg-surface shadow-xl"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="project-modal-title">

                <form wire:submit="save">
                    {{-- ── header ────────────────────────────────────────────
                         Royal, matching the app header, so the dialog reads as part of
                         the product rather than as a browser artefact dropped on top. --}}
                    <div class="flex items-start justify-between gap-4 rounded-t-2xl bg-royal-900 px-5 py-3.5 text-ink-inverse">
                        <div>
                            <h2 id="project-modal-title" class="text-base font-semibold tracking-tight">
                                {{ $projectId ? 'Edit project' : 'New project' }}
                            </h2>
                            @if ($this->tracker)
                                <p class="mt-0.5 font-mono text-[11px] uppercase tracking-[0.08em] text-royal-300">
                                    {{ $this->tracker->name }}
                                </p>
                            @endif
                        </div>

                        <button type="button" wire:click="close"
                                class="-mr-1 rounded-lg px-2 py-1 text-lg leading-none text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-powder-300"
                                aria-label="Close">&times;</button>
                    </div>

                    {{-- ── body ────────────────────────────────────────────── --}}
                    <div class="space-y-4 px-5 py-4">

                        <div>
                            <label for="pm-name" class="block text-xs font-semibold text-ink">
                                Title <span class="text-health-stalled">*</span>
                            </label>
                            <input id="pm-name" wire:model="name" type="text" autofocus
                                   placeholder="What is the work?"
                                   class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                            @error('name') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="pm-description" class="block text-xs font-semibold text-ink">Description</label>
                            <textarea id="pm-description" wire:model="description" rows="3"
                                      placeholder="Context, scope, links — anything the next person needs."
                                      class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none"></textarea>
                            @error('description') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                        </div>

                        {{-- Dates sit together because they are read together: the pair is
                             what says whether this project is late, and a lone start date
                             says nothing at all. --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="pm-start" class="block text-xs font-semibold text-ink">
                                    Date started <span class="text-health-stalled">*</span>
                                </label>
                                <input id="pm-start" wire:model="startDate" type="date"
                                       class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                                @error('startDate') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="pm-due" class="block text-xs font-semibold text-ink">
                                    Due date <span class="text-health-stalled">*</span>
                                </label>
                                <input id="pm-due" wire:model="dueDate" type="date"
                                       class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                                @error('dueDate')
                                    <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p>
                                @elseif ($projectId && $dueDate === '')
                                    {{-- Deliberately not prefilled on an older card: a due
                                         date is a commitment, and a guessed one would be
                                         measured as though someone had made it. --}}
                                    <p class="mt-1 text-xs text-ink-faint">
                                        This project predates due dates. Set the one the team actually agreed.
                                    </p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="pm-owner" class="block text-xs font-semibold text-ink">
                                    Owner <span class="text-health-stalled">*</span>
                                </label>
                                {{-- FR-4.3 — members of this tracker only. You cannot make
                                     someone accountable for work they cannot see. --}}
                                {{-- Locked to you while "Only me" is on. Disabled rather than
                                     swapped for static text so the field keeps its place in the
                                     grid and its label stays associated; the value still posts,
                                     because Livewire sends the bound property, not the DOM. --}}
                                <select id="pm-owner" wire:model="ownerId" @disabled($onlyMe)
                                        class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none disabled:cursor-not-allowed disabled:bg-canvas disabled:text-ink-soft">
                                    <option value="">Select an owner…</option>
                                    @foreach ($this->members as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                @error('ownerId') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="pm-priority" class="block text-xs font-semibold text-ink">Priority</label>
                                <select id="pm-priority" wire:model="priority"
                                        class="mt-1 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                                @error('priority') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        {{-- FR-4.11 — the visibility switch.
                             Placed under Owner rather than beside the title because it is a
                             statement about audience, and the owner is the audience: with this
                             ticked the two are the same person by rule. --}}
                        <div class="rounded-xl border border-line-strong bg-canvas px-3.5 py-3">
                            <label class="flex cursor-pointer items-start gap-2.5">
                                <input type="checkbox" wire:model.live="onlyMe"
                                       class="mt-0.5 size-4 rounded border-line-strong text-royal-700 focus:ring-royal-600">
                                <span>
                                    <span class="block text-xs font-semibold text-ink">Only me</span>
                                    <span class="mt-0.5 block text-[11px] text-ink-faint">
                                        @if ($onlyMe)
                                            Hidden from everyone else on this tracker — the card, its
                                            comments and its activity log. Nobody is notified about it.
                                        @else
                                            Keep this card, and everything on it, visible to you alone.
                                        @endif
                                    </span>
                                </span>
                            </label>
                        </div>

                        {{-- Both of the controls below are SHARED surfaces: an assignee has to be
                             able to open the card, and a tag name lands in every member's filter.
                             Neither can coexist with "Only me", so they are removed rather than
                             disabled — a greyed-out control invites a question the answer to
                             which is "that would defeat the setting you just chose". --}}
                        <div @class(['hidden' => $onlyMe])>
                            <span class="block text-xs font-semibold text-ink">Assigned to</span>
                            <p class="mt-0.5 text-[11px] text-ink-faint">
                                Anyone on this tracker. The owner is accountable; assignees are doing it.
                            </p>

                            @if ($this->members->isEmpty())
                                <p class="mt-2 rounded-lg bg-canvas px-3 py-2 text-xs text-ink-soft">
                                    This tracker has no other active members yet.
                                </p>
                            @else
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                                    {{-- Whole-chip targets rather than a bare 16px box: a
                                         checkbox alone is well under a comfortable hit area,
                                         and the selected state has to be legible at a glance
                                         when six people are listed. --}}
                                    @foreach ($this->members as $member)
                                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1.5 text-sm transition {{ in_array($member->id, $assignees) ? 'border-royal-600 bg-powder-200 text-royal-900' : 'border-line-strong bg-surface text-ink-soft hover:border-powder-500' }}">
                                            <input type="checkbox" wire:model.live="assignees" value="{{ $member->id }}"
                                                   class="size-4 rounded border-line-strong text-royal-700 focus:ring-royal-600">
                                            {{ $member->name }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            @error('assignees.*') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                        </div>

                        <div @class(['hidden' => $onlyMe])>
                            <label for="pm-tags" class="block text-xs font-semibold text-ink">Tags</label>

                            @if ($tags)
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($tags as $tag)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-powder-200 py-0.5 pl-2.5 pr-1 text-xs font-medium text-royal-900">
                                            {{ $tag }}
                                            {{-- By index, not by value: a tag containing an
                                                 apostrophe would otherwise break out of the
                                                 attribute and silently stop being removable. --}}
                                            <button type="button" wire:click="removeTag({{ $loop->index }})"
                                                    class="rounded-full px-1 text-ink-faint hover:bg-powder-200 hover:text-royal-800"
                                                    aria-label="Remove tag {{ $tag }}">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Enter commits a chip. A datalist rather than a fixed picker:
                                 tags are free-typed (nobody has to configure anything first),
                                 but the ones already on this board are one keystroke away, which
                                 is what stops "vendor", "Vendor" and "vendors" all existing. --}}
                            <input id="pm-tags" wire:model="tagInput" list="pm-tag-options" type="text"
                                   wire:keydown.enter.prevent="addTag"
                                   placeholder="Type a tag and press Enter…"
                                   class="mt-1.5 w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink transition focus:border-royal-600 focus:ring-2 focus:ring-royal-600/20 focus:outline-none">

                            <datalist id="pm-tag-options">
                                @foreach ($this->suggestedTags as $suggestion)
                                    <option value="{{ $suggestion }}"></option>
                                @endforeach
                            </datalist>
                            @error('tags.*') <p class="mt-1 text-xs text-health-stalled">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- ── footer ──────────────────────────────────────────── --}}
                    <div class="flex items-center justify-between gap-3 rounded-b-2xl border-t border-line bg-canvas px-5 py-3">
                        {{-- The promise has to change with the setting. "Recorded in the
                             activity log" is still true of a private card — the rows are
                             written exactly as before — but it reads as "your colleagues will
                             see this", and on an Only me card they will not. --}}
                        <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                            {{ $onlyMe ? 'Logged for you only' : 'Recorded in the activity log' }}
                        </p>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="close"
                                    class="rounded-lg px-3 py-2 text-sm text-ink-soft transition hover:bg-powder-200 hover:text-royal-900">
                                Cancel
                            </button>
                            {{-- The one primary action in the dialog, and the only filled
                                 button in it. wire:loading disables it so a double-click
                                 cannot create the project twice. --}}
                            <button type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="save"
                                    class="rounded-lg bg-royal-900 px-4 py-2 text-sm font-medium text-ink-inverse shadow-sm transition hover:bg-royal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-700 disabled:opacity-60">
                                <span wire:loading.remove wire:target="save">{{ $projectId ? 'Save changes' : 'Create project' }}</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
