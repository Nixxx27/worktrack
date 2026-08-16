{{--
    FR-9 — the review screen.

    Two tabs, from two deliberately separate tables. "Work" is everything that happened
    to projects on the trackers you belong to; "System" is the admin audit log and is
    only rendered for someone holding audit.view.
--}}
<div class="min-h-screen bg-canvas">

    {{-- One shared header across every screen — see components/app-nav.blade.php.
         No nav pill is lit here: Activity is reached from the footer line, so the
         wordmark eyebrow is what says where you are. --}}
    <x-app-nav eyebrow="Activity log" />

    <div class="mx-auto max-w-6xl px-5 py-6">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Activity</h1>
                <p class="mt-0.5 max-w-2xl text-sm text-ink-soft">
                    Every project created, moved, edited, assigned and tagged, with who did it
                    and when. Anyone on a tracker can change anything on it — this is the record
                    that makes that safe.
                </p>
            </div>

            @if ($this->canSeeAuditLog())
                <div class="flex rounded-lg border border-line-strong bg-surface p-0.5 text-sm">
                    <button wire:click="$set('view', 'work')"
                            class="rounded-md px-3 py-1 {{ $view === 'work' ? 'bg-royal-900 font-medium text-white' : 'text-ink-soft hover:bg-canvas-sunken' }}">
                        Work
                    </button>
                    <button wire:click="$set('view', 'system')"
                            class="rounded-md px-3 py-1 {{ $view === 'system' ? 'bg-royal-900 font-medium text-white' : 'text-ink-soft hover:bg-canvas-sunken' }}">
                        System
                    </button>
                </div>
            @endif
        </div>

        {{-- ── filters ───────────────────────────────────────────────────────
             Bound to the URL, so a filtered view can be pasted into a message and
             opens the same way for the reader — subject to their own visibility. --}}
        <div class="mt-4 flex flex-wrap items-end gap-2 rounded-xl border border-line bg-surface p-3">
            @if ($view === 'work')
                <label class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Tracker</span>
                    <select wire:model.live="trackerId" class="rounded-lg border border-line-strong bg-surface px-2.5 py-1.5 text-sm">
                        <option value="">All trackers</option>
                        @foreach ($this->trackers as $tracker)
                            <option value="{{ $tracker->public_id }}">{{ $tracker->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Action</span>
                    <select wire:model.live="type" class="rounded-lg border border-line-strong bg-surface px-2.5 py-1.5 text-sm">
                        <option value="">All actions</option>
                        @foreach ($this->types as $option)
                            <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Who</span>
                <select wire:model.live="actorId" class="rounded-lg border border-line-strong bg-surface px-2.5 py-1.5 text-sm">
                    <option value="">Anyone</option>
                    @foreach ($this->actors as $actor)
                        <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">From</span>
                <input wire:model.live="from" type="date" class="rounded-lg border border-line-strong px-2.5 py-1.5 text-sm">
            </label>

            <label class="flex flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">To</span>
                <input wire:model.live="to" type="date" class="rounded-lg border border-line-strong px-2.5 py-1.5 text-sm">
            </label>

            <label class="flex flex-1 flex-col gap-1">
                <span class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                    {{ $view === 'work' ? 'Project name' : 'Action' }}
                </span>
                <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search…"
                       class="w-full rounded-lg border border-line-strong px-2.5 py-1.5 text-sm">
            </label>

            <button wire:click="clearFilters" class="rounded-lg px-3 py-1.5 text-sm text-ink-soft underline hover:text-ink">
                Clear
            </button>
        </div>

        {{-- ── work feed ─────────────────────────────────────────────────── --}}
        @if ($view === 'work')
            @php $activities = $this->activities; @endphp

            <div class="mt-4 overflow-hidden rounded-xl border border-line bg-surface">
                @forelse ($activities as $entry)
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-line-soft px-4 py-2.5 text-sm last:border-b-0">
                        <span class="font-medium">{{ $entry->user?->name ?? 'System' }}</span>
                        <span class="text-ink-soft">{{ $entry->describe() }}</span>
                        <span class="text-ink-faint">on</span>
                        <span class="font-medium">{{ $entry->project?->name ?? 'a deleted project' }}</span>

                        <span class="ml-auto flex items-center gap-2 font-mono text-[11px] text-ink-faint">
                            <span class="rounded bg-canvas-sunken px-1.5 py-0.5">{{ $entry->tracker?->name }}</span>
                            {{-- Absolute date on hover: "3 days ago" is unusable when you
                                 are reconstructing what happened on a specific afternoon. --}}
                            <time datetime="{{ $entry->created_at->toIso8601String() }}"
                                  title="{{ $entry->created_at->toDayDateTimeString() }}">
                                {{ $entry->created_at->diffForHumans() }}
                            </time>
                        </span>

                        @if (! $entry->counts_as_activity)
                            {{-- FR-4.7: in the feed, but deliberately not resetting the stall
                                 clock. Labelled so the row is not read as someone's work. --}}
                            <span class="w-full font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                                system-generated · does not reset the stall clock
                            </span>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-ink-faint">
                        No activity matches these filters.
                    </p>
                @endforelse
            </div>

            @if ($activities->hasPages())
                <div class="mt-4">{{ $activities->links() }}</div>
            @endif

        {{-- ── system audit log (admin only) ─────────────────────────────── --}}
        @elseif ($this->canSeeAuditLog())
            <div class="mt-4 overflow-hidden rounded-xl border border-line bg-surface">
                <div class="border-b border-line bg-canvas px-4 py-2">
                    <p class="text-xs text-ink-soft">
                        Sign-ins, break-glass attempts, approvals, role changes and tracker
                        configuration. Append-only, and never contains credentials.
                    </p>
                </div>

                @forelse ($this->auditEntries as $entry)
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b border-line-soft px-4 py-2.5 text-sm last:border-b-0">
                        <span class="font-mono text-xs font-medium">{{ $entry->action }}</span>
                        <span class="text-ink-faint">by</span>
                        <span class="font-medium">{{ $entry->actor_name ?? 'unauthenticated' }}</span>

                        @if ($entry->context_decoded)
                            <span class="w-full break-all font-mono text-[11px] text-ink-faint">
                                {{ collect($entry->context_decoded)->map(fn ($v, $k) => $k.'='.(is_scalar($v) ? $v : json_encode($v)))->implode('  ') }}
                            </span>
                        @endif

                        <span class="ml-auto flex items-center gap-2 font-mono text-[11px] text-ink-faint">
                            @if ($entry->ip_address)<span>{{ $entry->ip_address }}</span>@endif
                            <time title="{{ $entry->created_at }}">{{ \Carbon\Carbon::parse($entry->created_at)->diffForHumans() }}</time>
                        </span>
                    </div>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-ink-faint">No system events match these filters.</p>
                @endforelse

                <p class="border-t border-line-soft px-4 py-2 font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                    Showing the 200 most recent matching events
                </p>
            </div>
        @endif
    </div>
</div>
