@php use App\Support\Duration; @endphp
{{--
    The dashboard answers six questions in order of how much they cost to ignore:
    what is late, which team is carrying it, where work is stuck, is the queue growing,
    how long things take, and who is overloaded.

    Deadlines come first deliberately. Flow metrics describe a system; a date that has
    passed describes a promise that was broken, and that is the thing a department head
    is asked about.

    Every figure derives from recorded movement history or from a date somebody entered
    against the work itself. Nobody types a status, which is why these numbers can be
    trusted in a way self-reported ones cannot.

    COLOUR: severity only, and always from the semantic tokens — ontrack, atrisk,
    stalled. A raw Tailwind red here would be a fourth red that means nothing, and the
    board's rule that "a colour always means status" has to hold across screens.
--}}
<div class="min-h-screen bg-canvas">

    {{-- One shared header across every screen — see components/app-nav.blade.php.
         Navigation that changes shape per page makes one product feel like three. --}}
    <x-app-nav current="dashboard" eyebrow="Dashboard">
        {{-- FR-8.5: all trackers is the DEFAULT, not an option you have to find. --}}
        <select wire:model.live="trackerFilter"
                aria-label="Filter by tracker"
                class="rounded-lg border border-royal-700 bg-royal-800 px-2.5 py-1.5 text-sm text-ink-inverse focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-powder-300">
            <option value="">All trackers</option>
            @foreach ($this->trackers as $t)
                <option value="{{ $t->id }}">{{ $t->name }}</option>
            @endforeach
        </select>
    </x-app-nav>

    @php $m = $this->metrics; @endphp

    <div class="mx-auto max-w-6xl px-5 py-6">

        {{-- Every other screen opens on a heading; this one opens on the numbers,
             because a visible "Dashboard" title under a nav that already says
             Dashboard twice is a line of chrome the figures have to sit below.
             The h1 still has to exist, or the section headings below start the
             document at level two. --}}
        <h1 class="sr-only">Dashboard</h1>

        {{-- ── tiles ──────────────────────────────────────────────────────────
             Two rows of three rather than one row of six: the top row is what needs
             attention today, the bottom is how the team performs over time. Six
             equal tiles in a line would flatten that distinction, and at a sixth of
             the width every label wraps. --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <x-stat-tile label="In flight"
                         :value="$m['inFlight']['count']"
                         :note="$m['seesEverything'] ? 'across all trackers' : 'within your trackers'" />

            <x-stat-tile label="Overdue"
                         :value="$m['deadlines']['overdue']->count()"
                         :alert="$m['deadlines']['overdue']->isNotEmpty()"
                         :note="$m['deadlines']['without_target'] > 0
                             ? $m['deadlines']['without_target'].' in flight have no target date'
                             : 'every in-flight project has a target date'" />

            <x-stat-tile label="Stalled"
                         :value="$m['stalled']->count()"
                         :alert="$m['stalled']->isNotEmpty()"
                         note="no activity past threshold" />

            <x-stat-tile label="Median cycle time"
                         :value="Duration::humanDays($m['cycle']['median_seconds'])"
                         :note="'first worked → done · '.$m['cycle']['completed_count'].' completed'" />

            {{-- Shown as a rate over the measured set, with the set size beside it. A
                 bare "83%" invites the reader to assume it covers everything. --}}
            <x-stat-tile label="Delivered on time"
                         :value="$m['onTime']['rate'] === null ? '—' : $m['onTime']['rate'].'%'"
                         :note="$m['onTime']['measured'] === 0
                             ? 'no completed project carried a target date'
                             : $m['onTime']['on_time'].' of '.$m['onTime']['measured'].' met their target date'" />

            <x-stat-tile label="Oldest in flight"
                         :value="Duration::humanDays($m['inFlight']['oldest_seconds'])"
                         note="since created" />
        </div>

        {{-- M-D6: survivorship-bias correction. A median cycle time computed only over
             finished work IMPROVES the longer something is stuck, so the in-flight
             distribution has to sit beside it or the headline number misleads. --}}
        @if ($m['cycle']['median_seconds'] !== null || $m['inFlight']['count'] > 0)
            <p class="mt-3 rounded-lg bg-canvas-sunken px-4 py-2.5 text-xs text-ink-soft">
                Median cycle time counts only finished work, so it looks better the longer something stays stuck.
                Read it next to the {{ $m['inFlight']['count'] }} still in flight
                (median age {{ Duration::words($m['inFlight']['median_seconds']) }}).
                @if ($m['cycle']['skipped_active_count'] > 0)
                    {{ $m['cycle']['skipped_active_count'] }} completed project(s) skipped the work steps entirely and
                    are excluded from cycle time.
                @endif
                @if ($m['onTime']['unpromised'] > 0)
                    On-time delivery covers only the {{ $m['onTime']['measured'] }} completed
                    project(s) that carried a target date — {{ $m['onTime']['unpromised'] }} had none, and a target
                    date can be edited after the fact, so treat the rate as a floor on lateness rather than a score.
                @endif
            </p>
        @endif

        {{-- ── FR-8.9 deadlines ─────────────────────────────────────────────── --}}
        @php
            $overdue = $m['deadlines']['overdue'];
            $dueSoon = $m['deadlines']['due_soon'];
            $dated = $overdue->concat($dueSoon);
        @endphp

        <x-panel class="mt-6"
                 heading="Past due and due soon"
                 :note="'Live work with a target date on or before '.now(config('worktrack.default_timezone'))->addDays($m['deadlines']['soon_days'])->format('j M').'. Most overdue first.'">
            <x-slot:header>
                <p class="font-mono text-[11px] uppercase tracking-[0.08em] text-ink-faint">
                    <span @class(['text-health-stalled' => $overdue->isNotEmpty()])>{{ $overdue->count() }} overdue</span>
                    ·
                    <span>{{ $dueSoon->count() }} due within {{ $m['deadlines']['soon_days'] }} days</span>
                </p>
            </x-slot:header>

            @if ($dated->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-ink-faint">
                    Nothing overdue or due in the next {{ $m['deadlines']['soon_days'] }} days.
                    @if ($m['deadlines']['without_target'] > 0)
                        <span class="mt-1 block text-[11px]">
                            {{ $m['deadlines']['without_target'] }} in-flight project(s) carry no target date, so they
                            cannot appear here.
                        </span>
                    @endif
                </p>
            @else
                <div class="overflow-x-auto px-5 py-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line-soft text-left font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                                <th class="py-2 pr-3 font-normal">Project</th>
                                <th class="py-2 pr-3 font-normal">Step</th>
                                <th class="py-2 pr-3 font-normal">Owner · team</th>
                                <th class="py-2 pr-3 font-normal">Target</th>
                                <th class="py-2 text-right font-normal">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dated as $p)
                                @php $late = Duration::daysUntil($p->target_date) < 0; @endphp
                                <tr class="border-b border-line-soft last:border-0">
                                    <td class="py-2.5 pr-3">
                                        <x-project-link :project="$p">{{ $p->name }}</x-project-link>
                                        <span class="block text-[11px] text-ink-faint">{{ $p->tracker?->name }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-[11px] text-ink-faint">{{ $p->step?->name }}</td>
                                    {{-- Owner first, then anyone else assigned. Showing the owner alone
                                         hid every collaborator: the two are set independently, so an
                                         assignee who is not the owner appeared nowhere on this page. --}}
                                    @php
                                        $people = collect([$p->owner])->filter()->concat($p->assignees)->unique('id')->values();
                                    @endphp
                                    <td class="py-2.5 pr-3 text-[11px] text-ink-faint">
                                        @if ($people->isEmpty())
                                            Unassigned
                                        @else
                                            {{ $people->first()->name }}
                                            @if ($people->count() > 1)
                                                <span class="block">+ {{ $people->skip(1)->pluck('name')->join(', ') }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-3 font-mono text-[11px] tabular-nums text-ink-soft">
                                        {{ $p->target_date->format('j M Y') }}
                                    </td>
                                    <td class="py-2.5 text-right">
                                        <span @class([
                                            'rounded-full px-2 py-0.5 font-mono text-[10px] tabular-nums',
                                            'bg-health-stalled-bg text-health-stalled' => $late,
                                            'bg-canvas-sunken text-ink-soft' => ! $late,
                                        ])>
                                            {{ Duration::deadlineWords($p->target_date) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Who is carrying it — the roll-up the table cannot give. Scanning
                     rows and tallying in your head works at five and fails at forty. --}}
                @php
                    $byMember = $m['deadlines']['by_member'];
                    $shared = $byMember->sum('overdue') > $overdue->count();
                @endphp
                <div class="border-t border-line-soft px-5 py-4">
                    <p class="font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">Who is carrying it</p>

                    <div class="mt-2.5 flex flex-wrap gap-x-6 gap-y-2">
                        @foreach ($byMember as $row)
                            <span class="flex items-center gap-2">
                                <span class="text-sm">{{ $row['name'] }}</span>
                                @if ($row['overdue'] > 0)
                                    <span class="rounded-full bg-health-stalled-bg px-2 py-0.5 font-mono text-[10px] tabular-nums text-health-stalled">
                                        {{ $row['overdue'] }} late
                                    </span>
                                @endif
                                @if ($row['due_soon'] > 0)
                                    <span class="rounded-full bg-canvas-sunken px-2 py-0.5 font-mono text-[10px] tabular-nums text-ink-soft">
                                        {{ $row['due_soon'] }} due soon
                                    </span>
                                @endif
                            </span>
                        @endforeach
                    </div>

                    @if ($shared || ! $m['seesEverything'])
                        <p class="mt-3 text-[11px] text-ink-faint">
                            @if ($shared)
                                Counts the owner and every assignee, so work shared by two people counts for
                                both — these do not add up to the {{ $overdue->count() }} overdue above.
                            @endif
                            @unless ($m['seesEverything'])
                                {{-- Same labelled-partial-figure trade-off as Workload. --}}
                                Figures cover only trackers you belong to, so someone's real total may be higher.
                            @endunless
                        </p>
                    @endif
                </div>
            @endif
        </x-panel>

        {{-- ── FR-8.10 per-tracker comparison ───────────────────────────────
             Only on the unfiltered roll-up, and only with something to compare
             against — a scorecard of one row is a wider version of the tiles. --}}
        @if ($m['scorecard'] !== null && $m['scorecard']->count() > 1)
            <x-panel class="mt-5"
                     heading="Tracker scorecard"
                     :note="'Side by side, so a bad month belongs to a team rather than to the whole department. Completions cover the last '.$m['scorecard']->first()['window_days'].' days.'">
                <div class="overflow-x-auto px-5 py-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line-soft text-left font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                                <th class="py-2 pr-3 font-normal">Tracker</th>
                                <th class="py-2 pr-3 text-right font-normal">In flight</th>
                                <th class="py-2 pr-3 text-right font-normal">Overdue</th>
                                <th class="py-2 pr-3 text-right font-normal">Stalled</th>
                                <th class="py-2 pr-3 text-right font-normal">Median in step</th>
                                <th class="py-2 text-right font-normal">Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($m['scorecard'] as $row)
                                <tr class="border-b border-line-soft last:border-0">
                                    <td class="py-2.5 pr-3">
                                        <a href="{{ route('board', ['tracker' => $row['public_id']]) }}"
                                           class="rounded underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-600">
                                            {{ $row['name'] }}
                                        </a>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right font-mono tabular-nums">{{ $row['in_flight'] }}</td>
                                    <td @class([
                                        'py-2.5 pr-3 text-right font-mono tabular-nums',
                                        'text-health-stalled' => $row['overdue'] > 0,
                                        'text-ink-faint' => $row['overdue'] === 0,
                                    ])>{{ $row['overdue'] }}</td>
                                    <td @class([
                                        'py-2.5 pr-3 text-right font-mono tabular-nums',
                                        'text-health-stalled' => $row['stalled'] > 0,
                                        'text-ink-faint' => $row['stalled'] === 0,
                                    ])>{{ $row['stalled'] }}</td>
                                    <td class="py-2.5 pr-3 text-right font-mono tabular-nums text-ink-soft">
                                        {{ Duration::humanDays($row['median_step_seconds']) }}
                                    </td>
                                    <td class="py-2.5 text-right font-mono tabular-nums">{{ $row['completed'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif

        {{-- ── FR-8.1 where work is stuck ───────────────────────────────────── --}}
        <x-panel class="mt-5" heading="Where work is stuck" note="Longest time in current step first.">
            @if ($m['aging']->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-ink-faint">Nothing in flight.</p>
            @else
                {{-- FR-8.10 — the shape of the whole portfolio, above the worst eight.
                     A top-eight list cannot distinguish "eight bad ones out of fifty" from
                     "everything is old", and those call for opposite responses. --}}
                @php
                    $buckets = $m['agingBuckets'];
                    $bucketTotal = max((int) $buckets->sum('count'), 1);
                    $bucketTones = ['bg-health-ontrack', 'bg-step-active', 'bg-health-atrisk', 'bg-health-stalled'];
                @endphp
                <div class="border-b border-line-soft px-5 py-3.5">
                    <div class="flex h-2 overflow-hidden rounded bg-canvas-sunken" aria-hidden="true">
                        @foreach ($buckets as $i => $bucket)
                            @if ($bucket['count'] > 0)
                                <div class="{{ $bucketTones[$i] }}" style="width: {{ round($bucket['count'] / $bucketTotal * 100, 2) }}%"></div>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1">
                        @foreach ($buckets as $i => $bucket)
                            <span class="flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                                <i class="inline-block size-1.5 rounded-full {{ $bucketTones[$i] }}"></i>
                                {{ $bucket['label'] }}
                                <span class="text-ink-soft tabular-nums">{{ $bucket['count'] }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>

                @php $max = max($m['aging']->max(fn ($p) => (int) $p->current_step_entered_at->diffInSeconds(now())), 1); @endphp
                <div class="overflow-x-auto px-5 py-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line-soft text-left font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                                <th class="py-2 pr-3 font-normal">Project</th>
                                <th class="py-2 pr-3 font-normal">Step</th>
                                <th class="py-2 pr-3 font-normal">Health</th>
                                <th class="w-1/3 py-2 pr-3 font-normal">Time in step</th>
                                <th class="py-2 text-right font-normal">Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($m['aging'] as $p)
                                @php
                                    $secs = (int) $p->current_step_entered_at->diffInSeconds(now());
                                    // Bar length is time; bar colour is health, taken from the same
                                    // enum as the chip beside it. It used to come from day
                                    // thresholds invented here, which meant a project the system
                                    // called On Track could be painted red — two answers to one
                                    // question, on one row.
                                    $tone = match ($p->health->value) {
                                        'on_track' => 'bg-health-ontrack',
                                        'at_risk' => 'bg-health-atrisk',
                                        'stalled' => 'bg-health-stalled',
                                        'on_hold' => 'bg-health-onhold',
                                    };
                                    $chip = match ($p->health->value) {
                                        'on_track' => 'bg-health-ontrack-bg text-health-ontrack',
                                        'at_risk' => 'bg-health-atrisk-bg text-health-atrisk',
                                        'stalled' => 'bg-health-stalled-bg text-health-stalled',
                                        'on_hold' => 'bg-health-onhold-bg text-health-onhold',
                                    };
                                @endphp
                                <tr class="border-b border-line-soft last:border-0">
                                    <td class="py-2.5 pr-3">
                                        <x-project-link :project="$p">{{ $p->name }}</x-project-link>
                                        <span class="block text-[11px] text-ink-faint">{{ $p->tracker?->name }}</span>
                                    </td>
                                    <td class="py-2.5 pr-3 text-[11px] text-ink-faint">{{ $p->step?->name }}</td>
                                    <td class="py-2.5 pr-3">
                                        <span class="rounded-full px-2 py-0.5 font-mono text-[9px] uppercase tracking-[0.05em] {{ $chip }}">
                                            {{ str_replace('_', ' ', $p->health->value) }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 pr-3">
                                        <x-meter :value="$secs" :max="$max" :tone="$tone" class="min-w-[80px]" />
                                    </td>
                                    <td class="py-2.5 text-right font-mono tabular-nums">{{ intdiv($secs, 86400) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panel>

        <div class="mt-5 grid gap-5 lg:grid-cols-2">

            {{-- ── FR-8.4 flow: arrivals against completions ─────────────────
                 The chart that answers "is the queue growing", which a completions-only
                 bar chart cannot: twelve finished looks like a good month until you see
                 that eighteen arrived. --}}
            @php
                $flow = $m['flow'];
                $arrived = (int) $flow->sum('arrived');
                $completed = (int) $flow->sum('completed');
                $net = $arrived - $completed;
                $maxFlow = max($flow->max('arrived') ?? 0, $flow->max('completed') ?? 0, 1);

                // Parsed with an explicit day. createFromFormat('Y-m', '2026-02') fills the
                // missing day from TODAY, so on the 30th of any month that reads as 30
                // February and Carbon rolls it into March — the axis silently mislabels
                // itself for the last two days of every long month.
                $monthName = fn (string $ym, string $format) => \Carbon\Carbon::createFromFormat('Y-m-d', $ym.'-01')->format($format);
            @endphp
            <x-panel heading="Arrivals against completions"
                     :note="'New work in versus finished work out, bucketed in '.config('worktrack.default_timezone').'.'">
                <div class="px-5 py-4">
                    {{-- The chart is decoration over this list, not the only way to read it. --}}
                    <p class="sr-only">
                        Last {{ $flow->count() }} months.
                        @foreach ($flow as $month => $row)
                            {{ $monthName($month, 'F Y') }}:
                            {{ $row['arrived'] }} arrived, {{ $row['completed'] }} completed.
                        @endforeach
                    </p>

                    <div class="flex h-28 items-end gap-2">
                        @foreach ($flow as $month => $row)
                            <div class="flex flex-1 flex-col items-center gap-1">
                                <div class="flex h-20 w-full items-end justify-center gap-1">
                                    {{-- A zero month draws a hairline in the line tone rather than a
                                         short coloured stub: a 3px bar reads as "a little", and the
                                         difference between a little and none is the whole point. --}}
                                    @foreach ([['arrived', 'bg-step-intake'], ['completed', 'bg-step-terminal']] as [$key, $tone])
                                        <div @class(['w-2.5 rounded-t', $tone => $row[$key] > 0, 'bg-line-strong' => $row[$key] === 0])
                                             style="height: {{ $row[$key] === 0 ? 1 : max(3, (int) round($row[$key] / $maxFlow * 80)) }}px"></div>
                                    @endforeach
                                </div>
                                <span class="font-mono text-[9px] text-ink-faint">
                                    {{ $monthName($month, 'M') }}
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line-soft pt-3 font-mono text-[10px] uppercase tracking-[0.08em] text-ink-faint">
                        <span class="flex items-center gap-1.5">
                            <i class="inline-block size-1.5 rounded-full bg-step-intake"></i>{{ $arrived }} arrived
                        </span>
                        <span class="flex items-center gap-1.5">
                            <i class="inline-block size-1.5 rounded-full bg-step-terminal"></i>{{ $completed }} completed
                        </span>
                    </div>

                    <p class="mt-2 text-xs text-ink-soft">
                        @if ($arrived === 0 && $completed === 0)
                            Nothing arrived or completed in this window.
                        @elseif ($net > 0)
                            The queue grew by {{ $net }} over these {{ $flow->count() }} months — more work arrived
                            than left.
                        @elseif ($net < 0)
                            The queue shrank by {{ abs($net) }} over these {{ $flow->count() }} months — the team
                            finished more than it took on.
                        @else
                            Arrivals and completions balanced exactly over these {{ $flow->count() }} months.
                        @endif
                    </p>
                </div>
            </x-panel>

            {{-- ── FR-8.1 bottleneck by step TYPE ───────────────────────────── --}}
            <x-panel heading="Where time goes"
                     note="Grouped by step type, not step name — each tracker names its columns differently.">
                <div class="px-5 py-3">
                    @php
                        $labels = ['intake' => 'Waiting', 'active' => 'Being worked', 'terminal' => 'Completed'];
                        $tones = ['intake' => 'bg-step-intake', 'active' => 'bg-step-active', 'terminal' => 'bg-step-terminal'];
                        $maxAvg = max(collect($m['byStepType'])->max('avg_seconds') ?? 1, 1);
                    @endphp
                    @forelse ($m['byStepType'] as $type => $row)
                        <div class="py-2">
                            <div class="flex items-baseline justify-between text-sm">
                                <span>{{ $labels[$type] ?? $type }}</span>
                                <span class="font-mono tabular-nums text-ink-soft">
                                    {{ Duration::humanDays($row['avg_seconds']) }} avg
                                    <span class="text-[11px] text-ink-faint">· {{ $row['occupancies'] }}×</span>
                                </span>
                            </div>
                            <x-meter class="mt-1"
                                     :value="$row['avg_seconds']"
                                     :max="$maxAvg"
                                     :tone="$tones[$type] ?? 'bg-step-active'" />
                        </div>
                    @empty
                        <p class="py-9 text-center text-sm text-ink-faint">
                            No completed step occupancies yet. Move a card to start measuring.
                        </p>
                    @endforelse
                </div>
            </x-panel>

            {{-- ── FR-8.3 stalled watchlist ─────────────────────────────────── --}}
            <x-panel heading="Stalled watchlist" note="On-hold projects are excluded — a planned pause is not rot.">
                <div class="px-5 py-2">
                    @forelse ($m['stalled'] as $p)
                        <div class="flex items-center gap-3 border-b border-line-soft py-2.5 last:border-0">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm">
                                    <x-project-link :project="$p">{{ $p->name }}</x-project-link>
                                </p>
                                <p class="text-[11px] text-ink-faint">
                                    {{ $p->tracker?->name }} · {{ $p->owner?->name ?? 'Unassigned' }} · {{ $p->step?->name }}
                                </p>
                            </div>
                            <span class="font-mono text-sm font-semibold tabular-nums text-health-stalled">
                                {{ Duration::humanDays((int) $p->last_activity_at->diffInSeconds(now())) }}
                            </span>
                        </div>
                    @empty
                        <p class="py-10 text-center text-sm text-ink-faint">Nothing stalled.</p>
                    @endforelse
                </div>
            </x-panel>

            {{-- ── FR-8.4 workload ──────────────────────────────────────────── --}}
            <x-panel heading="Workload" note="Active projects per person.">
                <div class="px-5 py-3">
                    @php $maxW = max($m['workload']->max('count') ?? 1, 1); @endphp
                    @forelse ($m['workload'] as $row)
                        <div class="flex items-center gap-3 py-1.5">
                            <span class="w-32 truncate text-sm">{{ $row['name'] }}</span>
                            <x-meter class="flex-1"
                                     :value="$row['count']"
                                     :max="$maxW"
                                     :tone="$row['count'] >= 4 ? 'bg-health-atrisk' : 'bg-step-active'" />
                            <span class="font-mono text-sm tabular-nums">{{ $row['count'] }}</span>
                        </div>
                    @empty
                        <p class="py-9 text-center text-sm text-ink-faint">Nothing assigned yet.</p>
                    @endforelse

                    @unless ($m['seesEverything'])
                        {{-- The labelled-partial-figure trade-off. A Manager may see 3 for
                             someone who actually carries 7, and saying so is the only thing
                             that stops the number being quietly wrong. --}}
                        <p class="mt-3 border-t border-line-soft pt-3 text-[11px] text-ink-faint">
                            Counts cover only trackers you belong to, so someone's real total may be higher.
                            Only administrators see organisation-wide figures.
                        </p>
                    @endunless
                </div>
            </x-panel>
        </div>

        <x-app-footer class="mt-6">
            <span class="normal-case tracking-normal">
                Every figure here is derived from recorded movement history — nobody types a status.
            </span>
        </x-app-footer>
    </div>
</div>
