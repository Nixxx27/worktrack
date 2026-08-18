<?php

use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Models\Project;
use App\Models\Step;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * FR-3.10 — the order a board column is read in.
 *
 * Two claims in this file are worth more than the rest.
 *
 * The first is that undated cards SINK under a due-date sort. `target_date` is
 * nullable by FR-4.1, so a column holding some is the ordinary case — and the naive
 * ascending sort puts NULL first, stacking every card nobody has committed to above
 * the one due tomorrow. That is an exact inversion of the question the sort was
 * chosen to answer.
 *
 * The second is that a drag inside a sorted column writes NOTHING. `board_position`
 * is the manual order, and it survives underneath while another sort is displayed.
 * A drop that appended — or midpointed between two cards that only look adjacent
 * because a date put them next to each other — would scramble the order the user
 * gets back the moment they switch the sort off, to record a gesture the sort then
 * ignores.
 *
 * The fixture is built so that manual order differs from all four sorts. A fixture
 * where the expected order happened to match creation order would pass just as well
 * with the feature not wired up at all.
 */

/** Both clocks at once, deliberately disagreeing — see the fixture comment below. */
function stampClocks(Project $project, int $createdDaysAgo, int $activeDaysAgo): void
{
    $project->forceFill([
        'created_at' => now()->subDays($createdDaysAgo),
        'last_activity_at' => now()->subDays($activeDaysAgo),
    ])->save();
}

function sortBoard(): Testable
{
    return Livewire::actingAs(test()->admin)
        ->test(Board::class, ['tracker' => test()->tracker->public_id]);
}

/**
 * The order stored UNDERNEATH — board_position ascending, which is what a drag writes
 * and what the board falls back to when no sort is chosen.
 *
 * Read through the system context on purpose, same as BoardTest: a test asserting
 * where a card sits should not also be able to fail because the card became invisible.
 */
function positionOrder(int $stepId): array
{
    return SystemContext::run(fn () => Project::where('step_id', $stepId)
        ->orderBy('board_position')->pluck('name')->all());
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);

    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    $this->intake = $this->steps->first();
    $this->second = $this->steps->values()[1];

    $projects = app(ProjectService::class);

    // Every project lands in the tracker's first live step, so all four share a column
    // — which is what makes an assertion about rendered order an assertion about one
    // column rather than about the board's left-to-right layout.
    //
    // Created in this order, so MANUAL order is exactly Alpha, Bravo, Charlie, Delta.
    $this->alpha = $projects->create($this->tracker, ['name' => 'Alpha rollout', 'target_date' => '2026-09-30'], $this->admin);
    $this->bravo = $projects->create($this->tracker, ['name' => 'Bravo migration', 'target_date' => '2026-08-20'], $this->admin);
    $this->charlie = $projects->create($this->tracker, ['name' => 'Charlie audit'], $this->admin);
    $this->delta = $projects->create($this->tracker, ['name' => 'Delta cleanup', 'target_date' => '2026-09-01'], $this->admin);

    // created_at and last_activity_at disagree with each other AND with creation order.
    // Without that, "recently added" and "recent activity" could both be satisfied by
    // one accidental ordering and neither assertion would be measuring anything.
    //
    //            added   touched   due          →  manual position
    //   alpha    10d     1d        2026-09-30      1st
    //   bravo     5d     9d        2026-08-20      2nd
    //   charlie   1d     5d        (none)          3rd
    //   delta    20d    20d        2026-09-01      4th
    stampClocks($this->alpha, createdDaysAgo: 10, activeDaysAgo: 1);
    stampClocks($this->bravo, createdDaysAgo: 5, activeDaysAgo: 9);
    stampClocks($this->charlie, createdDaysAgo: 1, activeDaysAgo: 5);
    stampClocks($this->delta, createdDaysAgo: 20, activeDaysAgo: 20);
});

describe('the order a column is read in', function () {

    it('defaults to manual order — the order drag-and-drop wrote', function () {
        // Manual is the default because it is the only order a person can author.
        // FR-3.5's drag has nothing to express under any other, so the board cannot
        // open in one.
        sortBoard()
            ->assertSet('sort', '')
            ->assertSeeHtmlInOrder(['Alpha rollout', 'Bravo migration', 'Charlie audit', 'Delta cleanup']);
    });

    it('puts the most recently added card first', function () {
        sortBoard()->set('sort', 'newest')
            ->assertSeeHtmlInOrder(['Charlie audit', 'Bravo migration', 'Alpha rollout', 'Delta cleanup']);
    });

    it('puts the oldest card first', function () {
        sortBoard()->set('sort', 'oldest')
            ->assertSeeHtmlInOrder(['Delta cleanup', 'Alpha rollout', 'Bravo migration', 'Charlie audit']);
    });

    it('orders by most recent activity, which is not the same as most recently added', function () {
        // Alpha is the SECOND-oldest card and the most recently touched one. If this
        // ever matches the 'newest' expectation, the sort is reading the wrong clock:
        // last_activity_at means "someone did something to this work", where created_at
        // and updated_at both move for reasons nobody would call activity.
        sortBoard()->set('sort', 'activity')
            ->assertSeeHtmlInOrder(['Alpha rollout', 'Charlie audit', 'Bravo migration', 'Delta cleanup']);
    });

    it('orders by due date, soonest first', function () {
        sortBoard()->set('sort', 'due')
            ->assertSeeHtmlInOrder(['Bravo migration', 'Delta cleanup', 'Alpha rollout']);
    });

    it('sinks a card with no due date to the bottom of the column', function () {
        // THE SHARP ONE. Ascending on a nullable column puts NULL first, which would
        // read as "Charlie is the most urgent thing here" when the truth is that
        // nobody has committed to it at all. target_date stays nullable by FR-4.1, so
        // this is the normal case rather than an edge one.
        sortBoard()->set('sort', 'due')
            ->assertSeeHtmlInOrder(['Bravo migration', 'Delta cleanup', 'Alpha rollout', 'Charlie audit']);
    });

    it('keeps a stable order for cards the sort cannot separate', function () {
        // Same due date on two cards, so the comparator returns 0 and the fallback is
        // whatever order the query returned — board_position ascending. PHP's sorts
        // have been stable since 8.0, which is the only reason that holds; a board
        // that reshuffled tied cards between renders would read as one that had lost
        // track of the work.
        $this->charlie->forceFill(['target_date' => '2026-08-20'])->save();

        sortBoard()->set('sort', 'due')
            ->assertSeeHtmlInOrder(['Bravo migration', 'Charlie audit', 'Delta cleanup', 'Alpha rollout']);
    });
});

describe('a sort is not a filter', function () {

    it('never claims cards are hidden', function () {
        // hasFilters drives both the "N of M" count and the Clear button. A sort hides
        // nothing, so lighting either of them up would be a lie told by a control that
        // only reordered the column.
        sortBoard()->set('sort', 'due')
            ->assertSet('sort', 'due')
            ->assertDontSee('Clear filters')
            ->assertDontSee('of 4');
    });

    it('shows every card it started with', function () {
        sortBoard()->set('sort', 'due')
            ->assertSee('Alpha rollout')
            ->assertSee('Bravo migration')
            ->assertSee('Charlie audit')
            ->assertSee('Delta cleanup');
    });

    it('survives Clear filters', function () {
        // clearFilters() resets self::FILTERS, and 'sort' is deliberately not in it.
        // Folding it in would have been one character of work and would mean a button
        // labelled "Clear filters" silently reset an order nobody asked it to touch.
        sortBoard()
            ->set('sort', 'due')
            ->set('search', 'Bravo')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('sort', 'due');
    });

    it('is not exposed as a per-control reset', function () {
        // clearFilter() is allowlisted against self::FILTERS because $filter arrives
        // from the browser. 'sort' is not on that list, so the client cannot reach it
        // through this door.
        sortBoard()->set('sort', 'due')
            ->call('clearFilter', 'sort')
            ->assertStatus(400);
    });

    it('survives switching trackers, like every other tracker-agnostic control', function () {
        // NFR-U2's reasoning applies unchanged: nobody wants to re-choose an order
        // because they glanced at another board. A sort key names no step and no
        // person, so there is nothing about it that a different tracker cannot honour.
        sortBoard()->set('sort', 'activity')
            ->call('switchTracker', $this->other->public_id)
            ->assertSet('sort', 'activity');
    });

    it('renders the new order as soon as the sort changes', function () {
        // Two opposite sorts over one component, so a board that answered the first
        // choice and then ignored the second would be caught. It does NOT isolate
        // forgetSortCaches(): each set() is its own request, and the computed reads are
        // memoized per request, so there is never a stale value to trip over here. See
        // that method's own note — it is insurance, not a guard under load.
        sortBoard()
            ->set('sort', 'newest')
            ->assertSeeHtmlInOrder(['Charlie audit', 'Delta cleanup'])
            ->set('sort', 'oldest')
            ->assertSeeHtmlInOrder(['Delta cleanup', 'Charlie audit']);
    });
});

describe('a link carrying a sort', function () {

    it('opens the board already sorted', function () {
        // The whole reason sort is #[Url]-backed: "look at what I am looking at" should
        // be a pasted link, exactly as FR-3.6 requires of the filters.
        Livewire::withQueryParams(['sort' => 'due'])
            ->actingAs($this->admin)
            ->test(Board::class, ['tracker' => $this->tracker->public_id])
            ->assertSeeHtmlInOrder(['Bravo migration', 'Delta cleanup', 'Alpha rollout', 'Charlie audit']);
    });

    it('degrades an unrecognised sort to the manual board, never to an arbitrary one', function () {
        // A truncated or hand-edited link. FR-3.6's rule for a malformed filter is that
        // it degrades to the unfiltered board rather than to an empty one; the same
        // reasoning applies here, because the alternative is a column in an order the
        // control claims it is not in.
        sortBoard()->set('sort', 'nonsense')
            ->assertSeeHtmlInOrder(['Alpha rollout', 'Bravo migration', 'Charlie audit', 'Delta cleanup']);
    });

    it('reports an unrecognised sort as manual order, so the control cannot lie', function () {
        expect(sortBoard()->set('sort', 'nonsense')->instance()->sortKey)->toBe('')
            ->and(sortBoard()->set('sort', 'nonsense')->instance()->manualOrder)->toBeTrue();
    });
});

describe('dragging while a sort is on', function () {

    it('writes nothing when a card is dropped inside its own column', function () {
        // THE OTHER SHARP ONE. board_position is the manual order and it stays intact
        // underneath the sort. A drop here has no position to mean — the sort decides
        // where the card renders — so recording one would corrupt the order the user
        // gets back when they switch the sort off.
        //
        // ALPHA, not Delta, and that detail is the test. Alpha is FIRST in manual order,
        // so the wrong behaviours are all visible: appending sends it to the back, and
        // midpointing against Charlie moves it into the middle. Dragging the card that
        // is already last would leave an appending implementation looking identical to a
        // correct one — the first version of this test did exactly that and passed with
        // the guard deleted.
        $before = positionOrder($this->intake->id);

        sortBoard()->set('sort', 'due')
            ->call('moveProject', $this->alpha->public_id, $this->intake->id, $this->charlie->id)
            ->assertOk();

        expect(positionOrder($this->intake->id))->toBe($before)
            ->and($before)->toBe(['Alpha rollout', 'Bravo migration', 'Charlie audit', 'Delta cleanup']);
    });

    it('leaves the dragged card on the exact position it already had', function () {
        // The order surviving is the visible claim; the stored decimal surviving is the
        // literal one. A drop that renumbered the card without changing who it sits
        // between would still be spending the DECIMAL(20,10) precision that midpoint
        // insertion depends on (DD-17), one pointless drag at a time.
        $before = SystemContext::run(fn () => $this->alpha->fresh()->board_position);

        sortBoard()->set('sort', 'due')
            ->call('moveProject', $this->alpha->public_id, $this->intake->id, $this->charlie->id);

        expect(SystemContext::run(fn () => $this->alpha->fresh()->board_position))->toBe($before);
    });

    it('records no movement row for a refused reorder', function () {
        // project_step_movements is the declared source of truth for every metric, so a
        // reorder that quietly wrote one would corrupt the step clock the board exists
        // to display. Guaranteed twice over — this guard returns before the service is
        // called, and MovementRecorder independently no-ops a same-step move — which is
        // why this asserts the outcome rather than either mechanism.
        $before = SystemContext::run(fn () => $this->alpha->movements()->count());

        sortBoard()->set('sort', 'due')
            ->call('moveProject', $this->alpha->public_id, $this->intake->id, $this->charlie->id);

        expect(SystemContext::run(fn () => $this->alpha->movements()->count()))->toBe($before);
    });

    it('still moves a card to another column', function () {
        // The half of the drag that is never cosmetic. A sort must not turn the board
        // read-only.
        sortBoard()->set('sort', 'due')
            ->call('moveProject', $this->alpha->public_id, $this->second->id);

        expect($this->alpha->fresh()->step_id)->toBe($this->second->id);
    });

    it('appends a cross-column drop rather than trusting the card it landed under', function () {
        // The DOM order under a sort is a date, so "the card above the cursor" is an
        // arbitrary neighbour whose board_position is adjacent to nothing.
        // Midpointing against it would write a position the sort then ignores.
        $projects = app(ProjectService::class);

        // Two cards already in the destination, so "appended" and "midpointed" are
        // distinguishable outcomes rather than the same one.
        $projects->move($this->bravo, $this->second, $this->admin);
        $projects->move($this->charlie, $this->second, $this->admin);

        sortBoard()->set('sort', 'due')
            ->call('moveProject', $this->alpha->public_id, $this->second->id, $this->bravo->id);

        expect(positionOrder($this->second->id))->toBe(['Bravo migration', 'Charlie audit', 'Alpha rollout']);
    });

    it('honours the drop position again as soon as the sort is off', function () {
        // Manual order is not disabled by having been unused — the position maths is
        // untouched, it is only bypassed while another order is displayed.
        sortBoard()
            ->call('moveProject', $this->delta->public_id, $this->intake->id, $this->alpha->id)
            ->assertOk();

        expect(positionOrder($this->intake->id))
            ->toBe(['Alpha rollout', 'Delta cleanup', 'Bravo migration', 'Charlie audit']);
    });
});
