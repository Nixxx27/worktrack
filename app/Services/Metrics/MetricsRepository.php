<?php

namespace App\Services\Metrics;

use App\Authorization\AccessContext;
use App\Enums\ProjectHealth;
use App\Enums\StepType;
use App\Models\Project;
use App\Models\ProjectStepMovement;
use App\Models\Tracker;
use Illuminate\Support\Collection;

/**
 * The four FR-8 metric families.
 *
 * Every query here goes through Eloquent so TrackerVisibilityScope applies — a
 * Manager's dashboard totals cover only their own trackers, an Admin's cover
 * everything. Deliberately NOT raw query builders: appending a filter to a raw
 * builder can dissolve the tracker predicate through SQL operator precedence
 * (VERIFICATION.md authorization-4), and Eloquent's scope nesting is what prevents it.
 *
 * FR-8.7 — nothing here reads a manually entered status. Every figure derives from
 * project_step_movements or from read-model columns that are materialisations of it.
 */
/*
 * PRIVATE CARDS ARE EXCLUDED FROM EVERY AGGREGATE HERE — ->excludingPrivate() on each
 * of the five reads below, including for the owner who can see them everywhere else.
 *
 * Two reasons, and the second is a leak rather than a matter of taste. A private card
 * is not team throughput: a cycle time that quietly includes one person's private
 * notes is a number two people reading the same dashboard disagree about with nothing
 * on screen to explain the gap. And these results are cached under
 * AccessContext::cacheKey(), which distinguishes viewers by TRACKER SET alone — so two
 * members of the same trackers share an entry, and the first to warm it would serve
 * their own private work's figures to the second.
 */
class MetricsRepository
{
    public function __construct(private AccessContext $context) {}

    /**
     * Cache key for any memoised metric.
     *
     * VERIFICATION.md metrics-2: a cache keyed WITHOUT the viewer's tracker set serves
     * one user's cross-tracker roll-up to another with different memberships — a silent
     * NFR-S3 breach that raises no error and would never surface in functional testing.
     * Any caching added later MUST include this.
     */
    public function scopeKey(): string
    {
        return $this->context->cacheKey();
    }

    /** True only for an Admin — used to label partial figures honestly in the UI. */
    public function seesEverything(): bool
    {
        return $this->context->seesAllTrackers();
    }

    // ── FR-8.1 · time in step and aging ─────────────────────────────────────

    /** Live projects, longest time in their current step first. The "what do I chase" view. */
    public function aging(int $limit = 10, ?int $trackerId = null): Collection
    {
        return $this->liveProjects($trackerId)
            ->orderBy('current_step_entered_at')
            ->with(['tracker:id,name,public_id', 'step:id,name', 'owner:id,name'])
            ->limit($limit)
            ->get();
    }

    /**
     * Which columns hold work longest — the bottleneck report.
     *
     * Grouped by STEP TYPE, not step name (FR-3.7 / FR-8.8). Each tracker names its own
     * columns, so "In Progress" in one is not comparable to "Development" in another;
     * grouping by name would silently average unrelated things together.
     */
    public function averageSecondsByStepType(?int $trackerId = null): array
    {
        $rows = ProjectStepMovement::query()
            ->when($trackerId, fn ($q) => $q->where('tracker_id', $trackerId))
            ->whereNotNull('exited_at')
            ->selectRaw('to_step_type, AVG(duration_seconds) AS avg_seconds, COUNT(*) AS occupancies')
            ->groupBy('to_step_type')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [
            $r->to_step_type->value => [
                'avg_seconds' => (int) $r->avg_seconds,
                'occupancies' => (int) $r->occupancies,
            ],
        ])->all();
    }

    // ── FR-8.2 · cycle time ─────────────────────────────────────────────────

    /**
     * Completion timing over finished projects.
     *
     * NULL cycle times are excluded rather than coerced to zero — a project dragged
     * straight to Done never did the work, and counting it as zero days would drag every
     * average down while looking like an improvement.
     *
     * The median is computed in PHP over the filtered set. At this scale that is cheaper
     * and clearer than a window function, and medians must never come from a rollup.
     */
    public function cycleTime(?int $trackerId = null): array
    {
        $completed = Project::query()
            ->excludingPrivate()
            ->when($trackerId, fn ($q) => $q->where('tracker_id', $trackerId))
            ->whereNull('archived_at')
            ->whereNotNull('first_terminal_at')
            ->get(['cycle_time_seconds', 'lead_time_seconds', 'skipped_active', 'first_terminal_at']);

        // NOT ->filter() — a bare filter() drops 0 as falsy, which would silently
        // exclude fast work from every cycle-time figure. The whole point of this
        // metric is that NULL and 0 mean different things: NULL is "never entered a
        // work step", 0 is "finished almost immediately". Conflating them makes a
        // team that ships quickly look like a team that skips process.
        $cycles = $this->presentValues($completed->pluck('cycle_time_seconds'));

        return [
            'completed_count' => $completed->count(),
            'median_seconds' => $this->median($cycles),
            'mean_seconds' => $cycles->isEmpty() ? null : (int) round($cycles->avg()),
            'median_lead_seconds' => $this->median(
                $this->presentValues($completed->pluck('lead_time_seconds'))
            ),

            // FR-8.2 data-quality footnote. Surfaced beside the median rather than hidden,
            // because a headline cycle time computed over a set that excludes N projects
            // is only honest if N is visible.
            'skipped_active_count' => $completed->where('skipped_active', true)->count(),
        ];
    }

    /**
     * M-D6 — the in-flight age distribution, shown NEXT TO the completed median.
     *
     * Median cycle time alone is survivorship bias: it describes only work that
     * finished, and gets *better* the longer something is stuck. This is the correction,
     * and it belongs on the same screen or the number misleads.
     */
    public function inFlightAges(?int $trackerId = null): array
    {
        $ages = $this->liveProjects($trackerId)
            ->get(['created_at'])
            ->map(fn ($p) => (int) $p->created_at->diffInSeconds(now()))
            ->sort()->values();

        return [
            'count' => $ages->count(),
            'median_seconds' => $this->median($ages),
            'oldest_seconds' => $ages->last(),
        ];
    }

    // ── FR-8.3 · stalled watchlist ──────────────────────────────────────────

    /** Ranked worst-first. On Hold is excluded: a planned pause is not rot. */
    public function stalled(?int $trackerId = null): Collection
    {
        return $this->liveProjects($trackerId)
            ->where('health', ProjectHealth::Stalled)
            ->orderBy('last_activity_at')
            ->with(['tracker:id,name,public_id', 'step:id,name', 'owner:id,name'])
            ->get();
    }

    // ── FR-8.4 · throughput and workload ────────────────────────────────────

    /**
     * Arrivals and completions per local calendar month — the flow balance.
     *
     * Completions alone cannot answer the question a department head actually asks:
     * "is the queue growing?" Twelve finished in a month reads as a good month until
     * you see that eighteen arrived. Both series are therefore returned together and
     * charted together; a completions-only chart is the one that flatters.
     *
     * Zero-filled across the whole window. A groupBy over the rows alone omits months
     * with no rows entirely, which puts March next to June on the axis and draws a
     * continuous line over the gap — the chart then reads as steady output during a
     * quarter when nothing shipped.
     *
     * @return Collection<string, array{arrived: int, completed: int}> keyed 'Y-m', oldest first
     */
    public function flowByMonth(int $months = 6, ?int $trackerId = null): Collection
    {
        $tz = config('worktrack.default_timezone');
        $start = now($tz)->subMonths($months - 1)->startOfMonth();

        $bucket = function (string $column) use ($trackerId, $start, $tz) {
            return Project::query()
                ->excludingPrivate()
                ->when($trackerId, fn ($q) => $q->where('tracker_id', $trackerId))
                // Both series exclude archived work, and they must agree: if one half
                // counted withdrawn projects and the other did not, the gap between
                // the bars — the only thing this chart exists to show — would be an
                // artefact of the filter rather than a fact about the queue.
                ->whereNull('archived_at')
                ->whereNotNull($column)
                ->where($column, '>=', $start->copy()->utc())
                ->get([$column])
                // Bucketed in PHP after converting to display timezone. Asia/Manila is
                // UTC+8, so grouping raw UTC values in SQL misfiles everything before
                // 08:00 local into the previous month — a small, plausible,
                // self-consistent error that survives review because it looks right.
                ->groupBy(fn ($p) => $p->{$column}->setTimezone($tz)->format('Y-m'))
                ->map->count();
        };

        $arrived = $bucket('created_at');
        $completed = $bucket('first_terminal_at');

        return collect(range(0, $months - 1))
            ->mapWithKeys(function ($offset) use ($start, $arrived, $completed) {
                $month = $start->copy()->addMonths($offset)->format('Y-m');

                return [$month => [
                    'arrived' => (int) ($arrived[$month] ?? 0),
                    'completed' => (int) ($completed[$month] ?? 0),
                ]];
            });
    }

    /**
     * Active projects per person.
     *
     * Scoped to the viewer's trackers, so a Manager may see 3 for someone who actually
     * carries 7. The UI must say so — see the note in the dashboard view. Only an Admin
     * sees true organisation-wide totals.
     */
    public function workload(?int $trackerId = null): Collection
    {
        return $this->liveProjects($trackerId)
            ->with('assignees:id,name')
            ->get()
            ->flatMap(fn ($p) => $p->assignees->isEmpty()
                ? [['name' => 'Unassigned', 'id' => 0]]
                : $p->assignees->map(fn ($u) => ['name' => $u->name, 'id' => $u->id])->all())
            ->groupBy('id')
            ->map(fn ($rows) => ['name' => $rows->first()['name'], 'count' => $rows->count()])
            ->sortByDesc('count')
            ->values();
    }

    // ── FR-8.9 · deadline performance ───────────────────────────────────────

    /**
     * What is already late, and what is about to be.
     *
     * FR-3.9 made a due date compulsory on entry to the steps that need one, which is
     * only worth the friction if something reads the dates back. This is that read.
     *
     * Calendar boundaries use the org timezone (NFR-U5): a project due today is not
     * late until today ends *here*, and comparing a UTC clock against a local date
     * would mark work overdue eight hours early every evening.
     *
     * `without_target` is the honest denominator. A portfolio can show zero overdue
     * simply because nobody promised a date, and a headline that cannot distinguish
     * "on time" from "unpromised" is the one number a head should not be given.
     *
     * `by_member` answers the question the project list cannot: not "what is late"
     * but "who is carrying it". Derived from the same fetched set rather than a
     * second query, so the local-date rule below has exactly one home.
     *
     * @return array{overdue: Collection, due_soon: Collection, by_member: Collection, without_target: int, soon_days: int}
     */
    public function deadlines(?int $trackerId = null, int $soonDays = 7): array
    {
        $today = now(config('worktrack.default_timezone'))->toDateString();
        $horizon = now(config('worktrack.default_timezone'))->addDays($soonDays)->toDateString();

        // One query for both lists: same shape, and the horizon already bounds the set.
        // Splitting in SQL would be two round trips for one indexed range scan.
        $dated = $this->liveProjects($trackerId)
            ->whereNotNull('target_date')
            ->where('target_date', '<=', $horizon)
            ->orderBy('target_date')
            // assignees rides along because the per-member tally needs it and the
            // horizon already bounds this set — one extra pivot query, not an N+1.
            ->with(['tracker:id,name,public_id', 'step:id,name', 'owner:id,name', 'assignees:id,name'])
            ->get();

        // Y-m-d strings compare correctly lexicographically, and comparing them avoids
        // the timezone trap in the cast: target_date is a bare date and its Carbon is
        // midnight UTC, so ->lt() against a local now() is off by the offset.
        [$overdue, $dueSoon] = $dated->partition(fn ($p) => $p->target_date->toDateString() < $today);

        return [
            'overdue' => $overdue->values(),
            'due_soon' => $dueSoon->values(),
            'by_member' => $this->tallyByMember($overdue, $dueSoon),
            'without_target' => $this->liveProjects($trackerId)->whereNull('target_date')->count(),
            'soon_days' => $soonDays,
        ];
    }

    /**
     * Deadline pressure per person, worst first.
     *
     * Shared work counts for everyone it is shared with, so these figures sum higher
     * than the project counts they come from. That is the point — a project two people
     * are late on is two people's problem — but it means the total is not a total, and
     * the view says so rather than inviting the reader to add the column up.
     *
     * @return Collection<int, array{id: int, name: string, overdue: int, due_soon: int}>
     */
    private function tallyByMember(Collection $overdue, Collection $dueSoon): Collection
    {
        $tally = [];

        $count = function (Collection $projects, string $bucket) use (&$tally) {
            foreach ($projects as $project) {
                foreach ($this->accountableFor($project) as $person) {
                    $tally[$person['id']] ??= $person + ['overdue' => 0, 'due_soon' => 0];
                    $tally[$person['id']][$bucket]++;
                }
            }
        };

        $count($overdue, 'overdue');
        $count($dueSoon, 'due_soon');

        // Late outranks due-soon: someone with one overdue item needs chasing before
        // someone with four that are merely approaching.
        return collect($tally)
            ->sortByDesc(fn ($r) => [$r['overdue'], $r['due_soon']])
            ->values();
    }

    /**
     * Everyone answerable for a project: its owner and its assignees, deduplicated.
     *
     * Both, not either. Owner and assignees are written independently — changeOwner()
     * sets a column, syncAssignees() writes a pivot, and nothing keeps them in step.
     * Reading only the owner hides every collaborator added after the fact; reading
     * only assignees hides an owner nobody thought to also assign. Keyed by id so
     * someone who is both is still one person.
     *
     * @return Collection<int|string, array{id: int, name: string}>
     */
    private function accountableFor(Project $project): Collection
    {
        $people = collect([$project->owner])
            ->filter(fn ($u) => $u !== null)
            ->concat($project->assignees)
            ->keyBy('id')
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        // id 0 matches workload()'s convention, so the two panels name the gap the
        // same way. An unowned, unassigned project is still someone's problem and
        // must not silently vanish from the tally.
        return $people->isEmpty()
            ? collect([0 => ['id' => 0, 'name' => 'Unassigned']])
            : $people;
    }

    /**
     * Did finished work land by the date it was promised?
     *
     * Measured against the CURRENT target_date, which is editable. A date moved out
     * the day before delivery reads here as on time, and no amount of arithmetic can
     * recover the original promise — project_step_movements records movement, not
     * field edits. The UI says so beside the figure rather than presenting a rate that
     * quietly rewards moving the goalposts.
     *
     * @return array{measured: int, on_time: int, late: int, rate: int|null, unpromised: int}
     */
    public function onTimeDelivery(?int $trackerId = null): array
    {
        $tz = config('worktrack.default_timezone');

        $completed = Project::query()
            ->excludingPrivate()
            ->when($trackerId, fn ($q) => $q->where('tracker_id', $trackerId))
            ->whereNull('archived_at')
            ->whereNotNull('first_terminal_at')
            ->get(['first_terminal_at', 'target_date']);

        // Only work that carried a promise can be judged against one. Counting
        // undated projects as on time would drive the rate towards 100% as the
        // team got *worse* at setting dates.
        $promised = $completed->filter(fn ($p) => $p->target_date !== null);

        $onTime = $promised->filter(
            fn ($p) => $p->first_terminal_at->setTimezone($tz)->toDateString() <= $p->target_date->toDateString()
        )->count();

        return [
            'measured' => $promised->count(),
            'on_time' => $onTime,
            'late' => $promised->count() - $onTime,
            'rate' => $promised->isEmpty() ? null : (int) round($onTime / $promised->count() * 100),
            'unpromised' => $completed->count() - $promised->count(),
        ];
    }

    // ── FR-8.10 · portfolio shape and per-tracker comparison ────────────────

    /**
     * How long in-flight work has been sitting in its current step, as a distribution.
     *
     * The aging table shows the worst eight, which cannot distinguish "eight bad ones
     * and forty healthy" from "everything is old". Buckets answer that in one glance,
     * and the boundaries are fixed rather than derived so the shape stays comparable
     * from week to week.
     *
     * @return Collection<int, array{label: string, count: int, from: int, to: int|null}>
     */
    public function agingBuckets(?int $trackerId = null): Collection
    {
        $days = $this->liveProjects($trackerId)
            ->get(['current_step_entered_at'])
            ->map(fn ($p) => intdiv((int) $p->current_step_entered_at->diffInSeconds(now()), 86400));

        return collect([
            ['label' => '0–7d', 'from' => 0, 'to' => 7],
            ['label' => '8–14d', 'from' => 8, 'to' => 14],
            ['label' => '15–30d', 'from' => 15, 'to' => 30],
            ['label' => '31d+', 'from' => 31, 'to' => null],
        ])->map(fn ($b) => $b + [
            'count' => $days->filter(
                fn ($d) => $d >= $b['from'] && ($b['to'] === null || $d <= $b['to'])
            )->count(),
        ]);
    }

    /**
     * One row per tracker — the cross-team comparison the tracker filter cannot give.
     *
     * Deliberately ignores the dashboard's tracker filter: its whole purpose is the
     * side-by-side, and a one-row comparison is not one. The view therefore renders it
     * only on the unfiltered roll-up.
     *
     * Two queries regardless of tracker count: the live set and the completion counts
     * are each fetched once and grouped in PHP. Looping trackers and querying per row
     * is the version of this that looks fine on three trackers and dies on thirty.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function trackerScorecard(int $windowDays = 30): Collection
    {
        $tz = config('worktrack.default_timezone');
        $today = now($tz)->toDateString();

        $live = $this->liveProjects(null)
            ->get(['tracker_id', 'current_step_entered_at', 'health', 'target_date']);

        $completions = Project::query()
            ->excludingPrivate()
            ->whereNull('archived_at')
            ->whereNotNull('first_terminal_at')
            ->where('first_terminal_at', '>=', now($tz)->subDays($windowDays)->utc())
            ->get(['tracker_id'])
            ->groupBy('tracker_id')
            ->map->count();

        return Tracker::active()->orderBy('name')->get(['id', 'name', 'public_id'])
            ->map(function ($tracker) use ($live, $completions, $today, $windowDays) {
                $rows = $live->where('tracker_id', $tracker->id);

                return [
                    'name' => $tracker->name,
                    'public_id' => $tracker->public_id,
                    'in_flight' => $rows->count(),
                    'stalled' => $rows->where('health', ProjectHealth::Stalled)->count(),
                    'overdue' => $rows->filter(
                        fn ($p) => $p->target_date !== null && $p->target_date->toDateString() < $today
                    )->count(),
                    'median_step_seconds' => $this->median(
                        $rows->map(fn ($p) => (int) $p->current_step_entered_at->diffInSeconds(now()))
                            ->sort()->values()
                    ),
                    'completed' => (int) ($completions[$tracker->id] ?? 0),
                    'window_days' => $windowDays,
                ];
            })
            ->values();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * "Live" means not archived and not in a terminal step. Both halves matter: an
     * archived project is out of scope, and a finished one is not "in flight".
     */
    private function liveProjects(?int $trackerId)
    {
        return Project::query()
            ->excludingPrivate()
            ->when($trackerId, fn ($q) => $q->where('tracker_id', $trackerId))
            ->whereNull('archived_at')
            ->where('current_step_type', '!=', StepType::Terminal);
    }

    /**
     * Drop only NULLs, keep zeros, and return sorted for median computation.
     *
     * Exists because `->filter()` with no callback treats 0 as absent. See the note in
     * cycleTime() — that distinction is load-bearing, not pedantry.
     */
    private function presentValues(Collection $values): Collection
    {
        return $values
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values();
    }

    private function median(Collection $sorted): ?int
    {
        $n = $sorted->count();

        if ($n === 0) {
            return null;
        }

        return $n % 2 === 1
            ? (int) $sorted[intdiv($n, 2)]
            : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }
}
