<?php

namespace App\Livewire;

use App\Enums\ProjectHealth;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The board.
 *
 * Reads rely entirely on TrackerVisibilityScope: there is no explicit
 * where('tracker_id', ...) anywhere in this class, and that is the point — the scope
 * makes a query written here inherit isolation whether or not its author remembers.
 *
 * Writes are authorized through policies, never by what the UI chose to render. A
 * hidden button is presentation; the drop handler re-checks on every request.
 */
class Board extends Component
{
    /**
     * In the query string so a board is linkable — the tracker scorecard on the
     * dashboard links straight to a team's board, and "the board I'm looking at" is
     * now something you can paste to someone else.
     */
    #[Url(as: 'tracker', except: '')]
    public ?string $trackerId = null;

    /**
     * FR-8.11 — the card a link asked us to open.
     *
     * Every dashboard row is a link to /board?project=…, so a figure the head is
     * looking at is one click from the work behind it. In the query string rather than
     * a dispatched event so the link survives being bookmarked, pasted into chat, or
     * arrived at by someone who was not the one looking at the dashboard.
     *
     * NAMED ...Id, AND IT HAS TO BE. A public property may not share a name with an
     * action, because Livewire's $wire proxy resolves properties BEFORE it falls back
     * to calling a server method:
     *
     *     } else if (property in state) return state[property]
     *     } else if (...)               return getFallback(component)(property)
     *
     * This property was briefly called $openProject, which is also the name of the
     * action every card on this board calls. $wire.openProject then evaluated to a
     * string instead of a function, and every route into the drawer — the card body,
     * the title, the expand button — silently stopped working. Server-side tests all
     * passed, because the collision only exists in the browser.
     */
    #[Url(as: 'project', except: '')]
    public ?string $openProjectId = null;

    // ── FR-3.6 · filters ────────────────────────────────────────────────────────
    //
    // "Filters are shareable via URL" is the half of FR-3.6 that decides whether the
    // rest is worth having: the reason to filter a board is usually to show someone
    // else what you are looking at, and a filter that lives only in component state
    // turns that into a list of instructions in a chat message.
    //
    // Every one carries an `except` matching its empty value — `''` for the text ones,
    // `[]` for the lists — so an unfiltered board keeps a clean URL and only the filters
    // actually in use appear in a pasted link.

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // ── every categorical filter takes a LIST ───────────────────────────────────
    //
    // The question people actually bring to a board is rarely singular: "what are
    // Ana and Dennis on", "what is at risk or stalled". A one-value control answers
    // it by making you look three times and hold the union in your head — and the
    // union is the answer, so the tool should be the one holding it.
    //
    // Within one control the values are OR'd (assigned to Ana OR Dennis); across
    // controls they are AND'd (…AND at risk). Adding a second name widens, adding a
    // second control narrows — the behaviour every filter bar has trained people to
    // expect, and the reason both directions are reachable without a mode switch.
    //
    // DELIBERATELY UNTYPED, and normalised through $this->selected rather than at the
    // property. Two reasons, both about links:
    //
    //   - `?assignee=5` is not hypothetical — it is every board link shared before
    //     this change. Livewire swallows the TypeError a typed `array` would throw
    //     and keeps the default, so a typed property would turn those links into a
    //     silently unfiltered board. Untyped, the scalar arrives and is read as [5].
    //   - `?assignee=3,5` is what a person writes by hand. Livewire only ever emits
    //     the `assignee[0]=3&assignee[1]=5` form, but it costs nothing to accept the
    //     short one, and a shareable filter that is painful to type by hand is only
    //     half shareable.

    #[Url(as: 'health', except: [])]
    public $healthStates = [];

    #[Url(as: 'assignee', except: [])]
    public $assignees = [];

    #[Url(as: 'owner', except: [])]
    public $owners = [];

    #[Url(as: 'dept', except: [])]
    public $departmentIds = [];

    /** Due-date range, inclusive on both ends. */
    #[Url(as: 'due_from', except: '')]
    public string $dueFrom = '';

    #[Url(as: 'due_to', except: '')]
    public string $dueTo = '';

    // ── FR-3.10 · sort ──────────────────────────────────────────────────────────
    //
    // NOT one of the filters, and deliberately absent from self::FILTERS. A filter
    // hides cards; a sort only reorders the ones already there. Folding it in would
    // light up the "N of M" count and the Clear button over a board hiding nothing,
    // and make "Clear filters" reset an order nobody asked it to touch.
    //
    // #[Url] for the same reason every filter has one: "look at what I am looking at"
    // should be a pasted link, and a column read due-date-first is exactly the kind of
    // view someone wants to hand over.
    //
    // Empty means MANUAL — board_position ascending, the order drag-and-drop writes —
    // and it is the default because it is the only order a person can author. Under any
    // other, a drag within a column has nothing left to express (FR-3.5).
    #[Url(as: 'sort', except: '')]
    public string $sort = '';

    public function mount(?string $tracker = null): void
    {
        // Scoped: a public_id from an invisible tracker simply does not resolve.
        //
        // $this->trackerId is already populated when the URL carried ?tracker=, so it
        // is consulted before the fallbacks rather than overwritten by them.
        $this->trackerId = $tracker
            ?? $this->trackerId
            ?? $this->trackerOfOpenProject()
            ?? Tracker::active()->orderBy('name')->value('public_id');

        // Whatever shape the URL used, the controls bind to lists — so fold the raw
        // query-string value into one before anything renders. Without this the board
        // arriving from `?assignee=5` would filter correctly (the query reads through
        // $this->selected either way) while every box in the assignee menu showed
        // unchecked: a filter you cannot see is worse than one that was dropped.
        //
        // Safe to do here: SupportAttributes is registered ahead of
        // SupportLifecycleHooks, so #[Url] has already written the raw value by the
        // time mount() runs, and nothing overwrites what we put back.
        $this->healthStates = $this->selected['health'];
        $this->assignees = $this->selected['assignees'];
        $this->owners = $this->selected['owners'];
        $this->departmentIds = $this->selected['departments'];

        // Same argument, one step further: a pasted link naming someone who is not a
        // member of the tracker it opens has no box to check either.
        $this->dropPeopleOutsideTracker();
    }

    /**
     * Which board the linked card lives on.
     *
     * Without this, a link from an all-trackers roll-up opens the alphabetically first
     * board and a drawer describing a project that is not on it.
     *
     * Resolved through the visibility scope, so a guessed public_id falls back to the
     * default board rather than revealing that the id exists somewhere.
     */
    private function trackerOfOpenProject(): ?string
    {
        if (! $this->openProjectId) {
            return null;
        }

        return Project::where('public_id', $this->openProjectId)
            ->first()?->tracker?->public_id;
    }

    /**
     * The drawer closed, so the link that opened it should stop being part of the URL —
     * otherwise a refresh reopens a drawer the user deliberately dismissed.
     */
    #[On('project-drawer:closed')]
    public function forgetOpenProject(): void
    {
        $this->openProjectId = null;
    }

    #[Computed]
    public function trackers()
    {
        return Tracker::active()->orderBy('name')->get();
    }

    #[Computed]
    public function tracker(): ?Tracker
    {
        return $this->trackerId
            ? Tracker::where('public_id', $this->trackerId)->first()
            : null;
    }

    #[Computed]
    public function steps()
    {
        if (! $this->tracker) {
            return collect();
        }

        return Step::where('tracker_id', $this->tracker->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->get();
    }

    #[Computed]
    public function projects()
    {
        if (! $this->tracker) {
            return collect();
        }

        // Reads the denormalized columns only — no per-card subqueries, so the board
        // stays one indexed range scan at the 500-card target (NFR-P1).
        return $this->filtered()
            // board_position ascending WHATEVER the chosen sort, because it is the one
            // order idx_projects_board (tracker_id, archived_at, step_id, board_position)
            // can walk without a filesort. FR-3.10 reorders the fetched cards in PHP
            // instead — see sortCards() for why that is the cheaper half of the trade.
            ->orderBy('board_position')
            // Eager-loaded, not per-card: the card front shows owner, assignees and
            // tags, and lazy-loading them would be 3 queries × 500 cards against the
            // one-indexed-range-scan budget NFR-P1 sets for this screen.
            ->with(['owner:id,name', 'assignees:id,name', 'tags:id,name,color'])
            ->get()
            ->groupBy('step_id')
            ->map(fn (Collection $cards) => $this->sortCards($cards));
    }

    /**
     * FR-3.10 — one column's cards in the chosen order.
     *
     * IN PHP RATHER THAN IN SQL, and both halves of that are deliberate:
     *
     *   - An ORDER BY on any of these columns forfeits idx_projects_board, turning the
     *     board's single indexed range scan into a filesort. A column is at most a few
     *     hundred rows of a collection already in memory, so sorting it here costs
     *     nothing measurable and NFR-P1's budget stays exactly as it was measured.
     *   - A per-column order cannot be expressed in one query at all. Nothing asks for
     *     that today — the control is board-wide — but doing it here means the day it
     *     does, it is a different comparator per group and not a query per column.
     *
     * No explicit tie-break: PHP's sorts have been stable since 8.0, so cards the
     * comparator calls equal keep the order they arrived in — which is board_position
     * ascending, straight from the query. That matters more than it sounds. Two cards
     * created in the same second (a seeded import, a bulk paste) would otherwise be
     * free to swap places between renders, and a board that reshuffles when nothing
     * happened reads as a board that has lost track of the work.
     *
     * @param  Collection<int, Project>  $cards
     * @return Collection<int, Project>
     */
    private function sortCards(Collection $cards): Collection
    {
        $comparisons = match ($this->sortKey) {
            'newest' => [fn (Project $a, Project $b) => $b->created_at <=> $a->created_at],
            'oldest' => [fn (Project $a, Project $b) => $a->created_at <=> $b->created_at],

            // last_activity_at, not updated_at. It is NOT NULL and written by the
            // activity recorder, so it means "someone did something to this work" —
            // where updated_at also moves for a denormalized counter nobody touched.
            'activity' => [fn (Project $a, Project $b) => $b->last_activity_at <=> $a->last_activity_at],

            // Undated cards SINK. target_date stays nullable by FR-4.1, so a column
            // holding some is the normal case, not the edge one — and a plain ascending
            // sort puts NULL first, which would stack every card nobody has committed
            // to above the one due tomorrow. The first comparator is the nulls-last
            // pass: false <=> true is -1, so a dated card outranks an undated one.
            'due' => [
                fn (Project $a, Project $b) => ($a->target_date === null) <=> ($b->target_date === null),
                fn (Project $a, Project $b) => $a->target_date <=> $b->target_date,
            ],

            // Manual — the query already returned board_position ascending, so there is
            // nothing to do and no sort to pay for.
            default => [],
        };

        return $comparisons === [] ? $cards : $cards->sortBy($comparisons)->values();
    }

    /**
     * The orders a column can be read in, as the control lists them.
     *
     * Here rather than in the template for the same reason healthOptions() is: a
     * hand-written copy of the list in the view is a second place to forget when a key
     * is added, and this map is also the allowlist sortKey() validates against.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return [
            '' => 'Manual order',
            'newest' => 'Recently added',
            'activity' => 'Recent activity',
            'oldest' => 'Oldest first',
            'due' => 'Due date',
        ];
    }

    /**
     * The sort as the board actually applies it.
     *
     * Validated at READ, exactly like $this->selected and for the same two reasons: a
     * truncated `?sort=` in a forwarded link, and a tampered payload on every request
     * after the first. Anything unrecognised degrades to the manual board — the same
     * rule FR-3.6 sets for a malformed filter, and the honest answer here too, because
     * the alternative is a column in an order the control claims it is not in.
     */
    #[Computed]
    public function sortKey(): string
    {
        return array_key_exists($this->sort, $this->sortOptions) ? $this->sort : '';
    }

    /**
     * Is the board in the order drag-and-drop writes?
     *
     * Read in three places, which is the point of naming it: the control styles itself
     * from it, the column hands it to Sortable, and moveProject re-checks it before
     * trusting a drop position. A card's position within a column only means something
     * when the DOM order IS board_position order.
     */
    #[Computed]
    public function manualOrder(): bool
    {
        return $this->sortKey === '';
    }

    /**
     * FR-3.6 — the board query with the filter bar applied.
     *
     * ═══════════════════════════════════════════════════════════════════════════════
     * THE SEARCH TERM IS NESTED, AND HAS TO BE — VERIFICATION.md authorization-4.
     *
     * The obvious way to write a two-column search is the dangerous one:
     *
     *     ->where('tracker_id', $id)->whereNull('archived_at')
     *     ->where('name', 'like', $t)->orWhere('description', 'like', $t)
     *
     * AND binds tighter than OR, so that reads as "(this tracker AND live AND name
     * matches) OR (description matches)" — and the second branch is unqualified. Every
     * archived card, and every card in every OTHER tracker the viewer belongs to,
     * appears the moment someone types a search term.
     *
     * TrackerVisibilityScope survives this on its own — Eloquent's callScope() measures
     * the original where count and wraps scope conditions in their own group — so this
     * is not a cross-tracker breach. It is worse than it looks anyway: the explicit
     * tracker_id and archived_at predicates here are ordinary wheres with no such
     * protection, so a Member of three trackers searching "vpn" would see all three
     * boards' cards, plus everything archived, mixed into one column layout.
     *
     * Hence $q->where(Closure): the disjunction is always parenthesised.
     * ═══════════════════════════════════════════════════════════════════════════════
     */
    private function filtered(): Builder
    {
        $term = trim($this->search);
        $selected = $this->selected;

        return Project::where('tracker_id', $this->tracker->id)
            ->whereNull('archived_at')
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like));
            })
            // Each of these is a whereIn over values $this->selected has already
            // validated, so a hand-edited ?health=nonsense contributes nothing and the
            // board comes back unfiltered rather than empty — an empty board reads as
            // "there is no work here", which would be a lie told by a typo.
            //
            // An empty list is falsy, so `when` skips the clause entirely: no filter
            // and "no valid values" collapse to the same harmless thing.
            ->when($selected['health'], fn (Builder $q, array $health) => $q->whereIn('health', $health))
            ->when($selected['owners'], fn (Builder $q, array $ids) => $q->whereIn('owner_user_id', $ids))
            ->when($selected['departments'], fn (Builder $q, array $ids) => $q->whereIn('department_id', $ids))
            // One whereHas over the whole list, not one per name: nested inside the
            // EXISTS this asks "does this card have ANY of these people on it", where a
            // whereHas each would ask for ALL of them and answer a question nobody
            // brought — two names would return only the cards they share.
            ->when($selected['assignees'], fn (Builder $q, array $ids) => $q->whereHas(
                'assignees', fn (Builder $a) => $a->whereIn('users.id', $ids)
            ))
            // Cards with no due date are excluded by a date filter rather than swept in
            // at one end: "due this week" is a question about dated work, and a hundred
            // undated cards answering it would make the filter useless.
            ->when($this->validDate($this->dueFrom), fn (Builder $q, $from) => $q->whereDate('target_date', '>=', $from))
            ->when($this->validDate($this->dueTo), fn (Builder $q, $to) => $q->whereDate('target_date', '<=', $to));
    }

    /**
     * The categorical filters as both the query and the controls read them.
     *
     * One place, because the alternative is two: a query that normalises defensively
     * and a checkbox list that trusts the raw property would disagree the moment they
     * were handed anything unexpected — and a control showing unchecked boxes over a
     * filtered board is the specific failure this whole file keeps trying to avoid.
     *
     * Normalising at READ rather than only at mount matters for the requests after the
     * first: those hydrate from the client payload, not the URL, so this is also what
     * stands between a tampered `assignees: [{...}]` and the query builder.
     *
     * @return array{health: list<string>, assignees: list<int>, owners: list<int>, departments: list<int>}
     */
    #[Computed]
    public function selected(): array
    {
        $health = fn ($v) => is_scalar($v) ? ProjectHealth::tryFrom((string) $v)?->value : null;
        $id = fn ($v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

        return [
            'health' => $this->cleanList($this->healthStates, $health),
            'assignees' => $this->cleanList($this->assignees, $id),
            'owners' => $this->cleanList($this->owners, $id),
            'departments' => $this->cleanList($this->departmentIds, $id),
        ];
    }

    /**
     * One raw filter value — list, scalar, comma string or junk — as a clean list.
     *
     * $valid returns null for anything it does not recognise, and null is dropped
     * rather than kept as a falsy member: `[null]` is truthy in PHP and would apply a
     * `whereIn (null)` that matches nothing, turning a nonsense URL into an empty
     * board. Duplicates go too, so `?assignee=5,5` is one bind, not two.
     */
    private function cleanList(mixed $raw, callable $valid): array
    {
        $items = match (true) {
            is_array($raw) => $raw,
            $raw === null || $raw === '' => [],
            default => preg_split('/\s*,\s*/', (string) $raw, flags: PREG_SPLIT_NO_EMPTY),
        };

        return collect($items)
            ->map($valid)
            ->reject(fn ($v) => $v === null)
            ->unique()
            ->values()
            ->all();
    }

    /** A malformed date in a shared URL is ignored, never fatal. */
    private function validDate(string $value): ?string
    {
        try {
            return $value !== '' ? Carbon::parse($value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** How many cards the filters are hiding, so an empty board is never a mystery. */
    #[Computed]
    public function totalCards(): int
    {
        return $this->tracker
            ? Project::where('tracker_id', $this->tracker->id)->whereNull('archived_at')->count()
            : 0;
    }

    #[Computed]
    public function shownCards(): int
    {
        return $this->projects->sum(fn ($cards) => $cards->count());
    }

    #[Computed]
    public function hasFilters(): bool
    {
        // The VALIDATED lists, not the raw properties: `?health=nonsense` filters
        // nothing, so it must not light up the "N of M" count and the Clear button as
        // though it did.
        return trim($this->search) !== ''
            || $this->selected['health'] || $this->selected['assignees']
            || $this->selected['owners'] || $this->selected['departments']
            || $this->dueFrom !== '' || $this->dueTo !== '';
    }

    /**
     * The people who can appear as owner or assignee here — members of THIS tracker.
     *
     * Same shape as ProjectModal::members() on purpose: "who is on this tracker" is one
     * question, and two spellings of it are two things to keep in step.
     */
    #[Computed]
    public function filterPeople()
    {
        if (! $this->tracker) {
            return collect();
        }

        return User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')
                ->from('tracker_members')
                ->where('tracker_id', $this->tracker->id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function departments()
    {
        return DB::table('departments')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Health as the filter menu lists it — the order the old `<select>` used: the three
     * automatic states worsening downward, then the manual one that suppresses them.
     *
     * Here rather than spelled out in the template because the menu renders from a
     * value => label map, and a second hand-written copy of the enum is a second thing
     * to forget when a case is added.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function healthOptions(): array
    {
        return [
            ProjectHealth::OnTrack->value => 'On track',
            ProjectHealth::AtRisk->value => 'At risk',
            ProjectHealth::Stalled->value => 'Stalled',
            ProjectHealth::OnHold->value => 'On hold',
        ];
    }

    /** @var list<string> */
    private const FILTERS = ['search', 'healthStates', 'assignees', 'owners', 'departmentIds', 'dueFrom', 'dueTo'];

    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->forgetFilterCaches();
    }

    /** Empty one control without disturbing the rest — the menu's own "Clear". */
    public function clearFilter(string $filter): void
    {
        // Allowlisted, because $filter arrives from the browser: reset() takes any
        // property name, and an un-checked one would hand the client a reset button
        // for trackerId or openProjectId.
        abort_unless(in_array($filter, self::FILTERS, true), 400);

        $this->reset($filter);
        $this->forgetFilterCaches();
    }

    public function updated(string $property): void
    {
        // Every filter is a read, so nothing needs saving — but the computed reads are
        // memoized per request and would otherwise render the previous filter's results.
        //
        // strtok, because a checkbox bound to a list reports itself as `assignees.2`
        // when Livewire updates one element rather than replacing the array.
        if (in_array(strtok($property, '.'), self::FILTERS, true)) {
            $this->forgetFilterCaches();
        }

        // Separately, because sort is not a filter. Rolling 'sort' into FILTERS would
        // have got this line for free and three wrong behaviours with it: clearFilters()
        // would reset the order, clearFilter() would expose it as a per-control reset,
        // and hasFilters() would claim cards were hidden when none were.
        if ($property === 'sort') {
            $this->forgetSortCaches();
        }
    }

    public function switchTracker(string $publicId): void
    {
        $this->trackerId = $publicId;

        // NFR-U2 — "switching trackers does not lose the user's filters". Kept, with one
        // exception: a person filter naming someone who is not a member of the tracker
        // being opened cannot be represented in the new bar, so it would go on hiding
        // cards from a control that shows nothing selected. A filter you cannot see is
        // worse than one that was dropped. Search, health, department and the date range
        // are all tracker-agnostic and survive untouched, which is what the requirement
        // is actually protecting — nobody wants to retype a search.
        unset($this->tracker, $this->filterPeople);

        $this->dropPeopleOutsideTracker();
        $this->forgetFilterCaches();
    }

    /**
     * Keep only the people the current tracker can actually show.
     *
     * Per name rather than per filter: with a list, "Ana and Dennis" arriving at a
     * board Dennis is not on should become "Ana", not nothing. Dropping the whole
     * filter would be the old single-value behaviour applied to a control that no
     * longer has that excuse.
     */
    private function dropPeopleOutsideTracker(): void
    {
        $selected = $this->selected;

        if (! $selected['assignees'] && ! $selected['owners']) {
            return;   // nothing to prune, so nothing to look up
        }

        $memberIds = $this->filterPeople->pluck('id')->all();

        $this->assignees = array_values(array_intersect($selected['assignees'], $memberIds));
        $this->owners = array_values(array_intersect($selected['owners'], $memberIds));

        unset($this->selected);
    }

    private function forgetFilterCaches(): void
    {
        unset($this->steps, $this->projects, $this->totalCards, $this->shownCards, $this->hasFilters, $this->selected);
    }

    /**
     * A sort invalidates the card list and nothing else.
     *
     * Not totalCards or shownCards: reordering a column cannot change how many cards are
     * in it, and forgetting the counts here would only make this path look
     * interchangeable with the filter one when it is not.
     *
     * HONEST NOTE ON WHY THIS EXISTS. Livewire applies property updates before it
     * renders, and every computed here is read for the first time during render — so on
     * today's code path there is nothing stale to forget, and deleting this method
     * breaks no test. It is kept for two reasons: it mirrors forgetFilterCaches(), which
     * IS load-bearing (switchTracker mutates state and then reads computeds in the same
     * request), and the day sort acquires an action of that shape the absence would be a
     * silently stale board rather than an error. Cheap insurance, documented as
     * insurance rather than left to read as a guard that is holding something up.
     */
    private function forgetSortCaches(): void
    {
        unset($this->projects, $this->sortKey, $this->manualOrder);
    }

    /**
     * Hand the dialog the tracker being added to.
     *
     * Creation itself lives in ProjectModal, which re-checks this same Gate before it
     * writes. The check here only decides whether the dialog opens at all — a hidden
     * button is presentation, never access control.
     */
    public function newProject(): void
    {
        abort_unless($this->tracker !== null, 404);

        Gate::authorize('project.create');

        $this->dispatch('project-modal:create', tracker: $this->tracker->public_id);
    }

    public function editProject(string $publicId): void
    {
        $this->dispatch('project-modal:edit', project: $publicId);
    }

    /** FR-4.5 — open the card. */
    public function openProject(string $publicId): void
    {
        $this->dispatch('project-drawer:open', project: $publicId);
    }

    /**
     * The dialog saved something, so the board's cached reads are stale.
     *
     * Only the computed properties are forgotten — not the component's state — so a
     * save does not reset which tracker the user is looking at.
     */
    #[On('project-saved')]
    #[On('board:refresh')]
    public function refreshBoard(): void
    {
        unset($this->projects);
    }

    /**
     * FR-3.5 — the server-side check behind every drop.
     *
     * The optimistic UI has already moved the card by the time this runs, so a refusal
     * must be visible: the component re-renders from the database, which snaps the card
     * back to where it actually is.
     */
    public function moveProject(string $projectPublicId, int $stepId, ?int $afterProjectId = null): void
    {
        // Resolved here rather than injected: Livewire fills action parameters from the
        // client, and PHP forbids an optional parameter before a required one.
        $projects = app(ProjectService::class);

        // Both lookups resolve through the scope, so a project or step in an invisible
        // tracker produces a 404 rather than a 403 — nothing here confirms it exists.
        $project = Project::where('public_id', $projectPublicId)->firstOrFail();
        $step = Step::whereKey($stepId)->where('tracker_id', $project->tracker_id)->firstOrFail();

        Gate::authorize('move', $project);

        // FR-3.10 — a reorder inside a sorted column is not expressible, so nothing is
        // written.
        //
        // The column is ordered by a date, not by hand: there is no position in it for a
        // drag to mean. Appending would be worse than doing nothing, because it would
        // rewrite the board_position the user gets back the moment they switch the sort
        // off — scrambling the manual order to record a gesture the sort then ignores.
        //
        // Forgetting the cached cards is the whole effect: the optimistic drag already
        // moved the card client-side, and re-rendering from the database is what undoes
        // it — the same mechanism a refused drop uses. The browser declines to send this
        // call at all, but moveProject is a server action and its arguments come from
        // the client.
        //
        // Cross-column drops fall through: entering a different step is never cosmetic,
        // and a card arriving somewhere new has no position there to preserve.
        if (! $this->manualOrder && $project->step_id === $step->id) {
            unset($this->projects);

            return;
        }

        // The due-date gate, BEFORE anything is written.
        //
        // Order is not stylistic. MovementRecorder::recordMove resets the step clock
        // and project_step_movements is the declared source of truth for every metric,
        // so a "let it through and ask afterwards" version would leave a corrupted
        // cycle time behind every dismissed dialog. Nothing is written until the date
        // exists.
        if ($this->needsDueDate($project, $step)) {
            // Snaps the card back: the optimistic drag already moved it client-side,
            // and re-rendering from the database is what undoes that.
            unset($this->projects);

            // The prompt re-issues this same call once the date is saved, which is why
            // the drop position travels with it — otherwise a card dropped between two
            // others would silently land at the bottom of the column on the second
            // attempt.
            $this->dispatch(
                'due-date-prompt:open',
                project: $project->public_id,
                step: $step->id,
                afterProject: $afterProjectId,
            );

            return;
        }

        // Under a sort, $afterProjectId is "the card the cursor left this one under" read
        // from a DOM ordered by a date — an arbitrary neighbour whose board_position is
        // not adjacent to anything. Midpointing between it and its successor would write
        // a position the sort then ignores, so the card appends instead and the sort
        // decides where it renders. In manual order the DOM order IS board_position
        // order, and the drop means exactly what it looks like.
        $position = $this->manualOrder
            ? $this->resolveDropPosition($step->id, $afterProjectId, $projects)
            : null;

        $projects->move($project, $step, auth()->user(), $position);

        unset($this->projects);
    }

    /**
     * Does this drop need a due date first?
     *
     * Three conditions, all required:
     *   - the DESTINATION step is flagged. The flag is about entering a column, so
     *     dragging a card back to an unflagged Backlog is never gated — which is the
     *     right reading: retreating from a commitment does not require making one.
     *   - the step actually changes. A reorder inside the same column is not entering
     *     anywhere, and gating it would make a flagged column impossible to tidy.
     *   - the project has no due date yet. Cards that already have one pass straight
     *     through, so the gate is invisible to work that was recorded properly.
     */
    private function needsDueDate(Project $project, Step $step): bool
    {
        return $step->requires_due_date
            && $project->step_id !== $step->id
            && $project->target_date === null;
    }

    /**
     * The date was supplied, so run the drop that was refused.
     *
     * Re-entering moveProject rather than calling ProjectService directly is
     * deliberate: the policy check, the gate and the position maths all live there,
     * and a second write path would be a second place for them to be forgotten. By
     * now the project has a target_date, so the gate falls through.
     */
    #[On('due-date-prompt:saved')]
    public function resumeMove(string $project, int $step, ?int $afterProject = null): void
    {
        $this->moveProject($project, $step, $afterProject);
    }

    /**
     * Where the drop lands, as a board_position.
     *
     * "No card above it" and "no valid target" both arrive as a null $afterProjectId
     * but mean opposite ends of the column, and collapsing them is what used to send
     * every card dropped on the top straight back to the bottom.
     */
    private function resolveDropPosition(int $stepId, ?int $afterProjectId, ProjectService $projects): ?float
    {
        // The client omits the id only when the card landed at index 0 — cards are the
        // column's only children, so nothing above it means the top of the column.
        if ($afterProjectId === null) {
            $first = Project::where('step_id', $stepId)
                ->orderBy('board_position')
                ->value('board_position');

            // An empty column has no top to sit above; appending is the same position.
            return $first === null ? null : $projects->positionBetween(null, (float) $first);
        }

        $after = Project::whereKey($afterProjectId)->first();

        // A card that has since been moved or archived by someone else. Unlike the case
        // above this genuinely has no target, so the bottom of the column is the honest
        // answer rather than a guess at what the dragger meant.
        if ($after === null) {
            return null;
        }

        $next = Project::where('step_id', $stepId)
            ->where('board_position', '>', $after->board_position)
            ->orderBy('board_position')
            ->value('board_position');

        return $projects->positionBetween((float) $after->board_position, $next !== null ? (float) $next : null);
    }

    public function render()
    {
        return view('livewire.board');
    }
}
