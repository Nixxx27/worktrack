<?php

use App\Authorization\SystemContext;
use App\Enums\ProjectHealth;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Models\Step;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * FR-3.6 — "Board filters: department, health flag, assignee, owner, date range, text
 * search. Filters are shareable via URL."
 *
 * The sharpest test in this file is the last one in the first block. A two-column text
 * search is the exact shape VERIFICATION.md authorization-4 describes: written the
 * obvious way, the `orWhere` dissolves every AND beside it, and typing a search term
 * pulls in cards the board was never meant to show.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);

    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    $this->joy = makeUser('joy@gmail.com');
    $this->nz = makeUser('nz@example.com');
    app(TrackerService::class)->addMember($this->tracker, $this->joy, $this->admin);
    app(TrackerService::class)->addMember($this->tracker, $this->nz, $this->admin);

    $projects = app(ProjectService::class);

    $this->vpn = $projects->create($this->tracker, [
        'name' => 'Branch VPN failover testing',
        'owner_user_id' => $this->joy->id,
        'assignees' => [$this->nz->id],
        'target_date' => '2026-09-04',
    ], $this->admin);

    $this->ups = $projects->create($this->tracker, [
        'name' => 'Replace ageing UPS units',
        'description' => 'Datacentre power, including the VPN rack',
        'owner_user_id' => $this->nz->id,
        'target_date' => '2026-08-21',
    ], $this->admin);

    $this->cctv = $projects->create($this->tracker, ['name' => 'CCTV storage expansion'], $this->admin);
});

function board(): Testable
{
    return Livewire::actingAs(test()->admin)
        ->test(Board::class, ['tracker' => test()->tracker->public_id]);
}

describe('text search', function () {

    it('matches on the card name', function () {
        board()->set('search', 'UPS')
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('matches on the description too', function () {
        // "I know we wrote it down somewhere on that card" is most of what a board
        // search is for, and the name is only half of what was written down.
        board()->set('search', 'Datacentre')
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('Branch VPN failover');
    });

    it('treats a wildcard character as text, not as a pattern', function () {
        // Otherwise a single '%' matches every card and reads like a broken filter.
        board()->set('search', '%')
            ->assertDontSee('Replace ageing UPS units')
            ->assertSee('None of the 3 cards on this board match');
    });

    it('never lets the search term dissolve the tracker or archived predicates', function () {
        // ═══════════════════════════════════════════════════════════════════════════
        // VERIFICATION.md authorization-4, as a live regression test.
        //
        // Written the obvious way — ->where('tracker_id', $id)->whereNull('archived_at')
        // ->where('name','like',$t)->orWhere('description','like',$t) — AND binds tighter
        // than OR, so the description branch carries no tracker and no archived
        // constraint at all. Both decoys below match the term "VPN" and both must stay
        // invisible.
        // ═══════════════════════════════════════════════════════════════════════════
        $otherTrackerCard = app(ProjectService::class)->create(
            $this->other, ['name' => 'VPN concentrator upgrade', 'description' => 'VPN work in another tracker'], $this->admin
        );

        $archived = app(ProjectService::class)->create(
            $this->tracker, ['name' => 'Old VPN decommission', 'description' => 'VPN, archived last year'], $this->admin
        );
        app(ProjectService::class)->archive($archived, $this->admin);

        board()->set('search', 'VPN')
            ->assertSee('Branch VPN failover testing')          // the real match
            ->assertSee('Replace ageing UPS units')             // matched on description
            ->assertDontSee('VPN concentrator upgrade')         // other tracker
            ->assertDontSee('Old VPN decommission');            // archived

        expect($otherTrackerCard->tracker_id)->not->toBe($this->tracker->id);
    });
});

describe('the other five filters', function () {

    it('filters by health', function () {
        $this->ups->forceFill(['health' => ProjectHealth::AtRisk])->save();

        board()->set('healthStates', ['at_risk'])
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('filters by owner', function () {
        board()->set('owners', [$this->joy->id])
            ->assertSee('Branch VPN failover testing')
            ->assertDontSee('Replace ageing UPS units');
    });

    it('filters by assignee, which is not the same question as owner', function () {
        // nz OWNS the UPS card and is ASSIGNED to the VPN one. A filter that conflated
        // them would answer "what is on my plate" with "what has my name on it".
        board()->set('assignees', [$this->nz->id])
            ->assertSee('Branch VPN failover testing')
            ->assertDontSee('Replace ageing UPS units');
    });

    it('filters by department', function () {
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Networking', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->vpn->forceFill(['department_id' => $deptId])->save();

        board()->set('departmentIds', [$deptId])
            ->assertSee('Branch VPN failover testing')
            ->assertDontSee('CCTV storage expansion');
    });

    it('filters by a due-date range, excluding undated cards', function () {
        // "Due this month" is a question about dated work. A hundred undated cards
        // answering it would make the filter useless.
        board()->set('dueFrom', '2026-08-01')->set('dueTo', '2026-08-31')
            ->assertSee('Replace ageing UPS units')       // due 21 Aug
            ->assertDontSee('Branch VPN failover')        // due 4 Sep
            ->assertDontSee('CCTV storage expansion');    // no due date
    });

    it('combines filters as AND, not OR', function () {
        $this->vpn->forceFill(['health' => ProjectHealth::AtRisk])->save();

        board()->set('healthStates', ['at_risk'])->set('owners', [$this->nz->id])
            ->assertDontSee('Branch VPN failover testing')   // at risk, but joy owns it
            ->assertDontSee('Replace ageing UPS units');     // nz owns it, but on track
    });
});

/**
 * The half of FR-3.6 this block exists for: adding a second name WIDENS, adding a
 * second control NARROWS. Both directions have to be reachable without a mode switch,
 * because "what are Ana and Dennis on, of the things at risk" is one question.
 */
describe('more than one value per filter', function () {

    it('shows work belonging to any of the selected owners', function () {
        board()->set('owners', [$this->joy->id, $this->nz->id])
            ->assertSee('Branch VPN failover testing')       // joy owns
            ->assertSee('Replace ageing UPS units')          // nz owns
            ->assertDontSee('CCTV storage expansion');       // unowned
    });

    it('shows work assigned to any of the selected people, not only work they share', function () {
        // The failure this pins is a whereHas PER NAME instead of one whereIn: that
        // reads as "assigned to Joy AND to nz" and answers a question nobody asked —
        // two names would return only the cards they happen to share, so picking a
        // colleague would make the board emptier rather than fuller.
        app(ProjectService::class)->create($this->tracker, [
            'name' => 'Rack tidy-up', 'assignees' => [$this->joy->id],
        ], $this->admin);

        board()->set('assignees', [$this->joy->id, $this->nz->id])
            ->assertSee('Branch VPN failover testing')       // nz assigned
            ->assertSee('Rack tidy-up')                      // joy assigned
            ->assertDontSee('Replace ageing UPS units');     // nobody assigned
    });

    it('shows work in any of the selected health states', function () {
        $this->vpn->forceFill(['health' => ProjectHealth::AtRisk])->save();
        $this->ups->forceFill(['health' => ProjectHealth::Stalled])->save();

        board()->set('healthStates', ['at_risk', 'stalled'])
            ->assertSee('Branch VPN failover testing')
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');       // on track
    });

    it('shows work in any of the selected departments', function () {
        $networking = DB::table('departments')->insertGetId([
            'name' => 'Networking', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $facilities = DB::table('departments')->insertGetId([
            'name' => 'Facilities', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->vpn->forceFill(['department_id' => $networking])->save();
        $this->ups->forceFill(['department_id' => $facilities])->save();

        board()->set('departmentIds', [$networking, $facilities])
            ->assertSee('Branch VPN failover testing')
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('still narrows when a second control is used', function () {
        // Two owners OR'd, then AND'd against health. Getting this backwards is the
        // whole reason the two directions are worth a test each.
        $this->vpn->forceFill(['health' => ProjectHealth::AtRisk])->save();

        board()->set('owners', [$this->joy->id, $this->nz->id])->set('healthStates', ['at_risk'])
            ->assertSee('Branch VPN failover testing')       // joy owns it, at risk
            ->assertDontSee('Replace ageing UPS units');     // nz owns it, on track
    });

    it('never returns a card twice when several of its assignees are selected', function () {
        // whereHas, not a join. A join would emit one row per matching pivot record, so
        // a card assigned to both people would render twice in its column and be counted
        // twice in "N of M".
        app(ProjectService::class)->update(
            $this->vpn, ['assignees' => [$this->joy->id, $this->nz->id]], $this->admin
        );

        $html = board()->set('assignees', [$this->joy->id, $this->nz->id])->html();

        // The card element itself, not the name — the name also appears in the drag
        // handle's label and would count more than once on a perfectly correct board.
        expect(substr_count($html, 'data-project="'.$this->vpn->public_id.'"'))->toBe(1);
    });

    it('clears one menu without disturbing the others', function () {
        board()->set('search', 'VPN')->set('owners', [$this->joy->id])->set('healthStates', ['at_risk'])
            ->call('clearFilter', 'owners')
            ->assertSet('owners', [])
            ->assertSet('search', 'VPN')
            ->assertSet('healthStates', ['at_risk']);
    });

    it('refuses to clear a property that is not a filter', function () {
        // clearFilter takes a property name straight from the browser, and reset() will
        // empty anything it is handed — including the tracker the board is showing.
        board()->call('clearFilter', 'trackerId')->assertStatus(400);
    });
});

describe('sharing and recovery', function () {

    it('reads every filter from the query string', function () {
        // FR-3.6's "shareable via URL" half. Without it the answer to "show me what you
        // mean" is a list of instructions rather than a link.
        Livewire::withQueryParams([
            'tracker' => $this->tracker->public_id,
            'q' => 'UPS',
            'owner' => [(string) $this->nz->id],
        ])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSet('search', 'UPS')
            ->assertSet('owners', [$this->nz->id])
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('still honours a link written before the filters took lists', function () {
        // ?owner=7, not ?owner[0]=7 — the form every board link shared before this
        // change uses. Livewire swallows the TypeError a typed array property would
        // throw and keeps the default, so getting this wrong is silent: the link opens
        // an unfiltered board and nobody learns that it stopped working.
        Livewire::withQueryParams([
            'tracker' => $this->tracker->public_id,
            'owner' => (string) $this->nz->id,
            'health' => 'on_track',
        ])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSet('owners', [$this->nz->id])
            ->assertSet('healthStates', ['on_track'])
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('Branch VPN failover testing');
    });

    it('accepts the comma form a person would type by hand', function () {
        // Livewire only ever writes ?owner[0]=3&owner[1]=5. Accepting ?owner=3,5 costs
        // nothing and is the difference between a filter that is shareable and one that
        // is shareable only by copy-paste.
        Livewire::withQueryParams([
            'tracker' => $this->tracker->public_id,
            'owner' => $this->joy->id.','.$this->nz->id,
        ])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSet('owners', [$this->joy->id, $this->nz->id])
            ->assertSee('Branch VPN failover testing')
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('ignores a nonsense health value rather than showing an empty board', function () {
        // A hand-edited or truncated URL should degrade to the unfiltered board. An
        // empty one looks like "there is no work here", which is a lie.
        Livewire::withQueryParams(['tracker' => $this->tracker->public_id, 'health' => 'nonsense'])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSee('Replace ageing UPS units')
            ->assertSee('CCTV storage expansion');
    });

    it('drops only the nonsense when a list is part valid', function () {
        // ['at_risk', 'nonsense'] must filter by at_risk, not fall back to everything
        // and not whereIn a null that matches nothing.
        $this->ups->forceFill(['health' => ProjectHealth::AtRisk])->save();

        Livewire::withQueryParams([
            'tracker' => $this->tracker->public_id,
            'health' => ['at_risk', 'nonsense'],
        ])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSee('Replace ageing UPS units')
            ->assertDontSee('CCTV storage expansion');
    });

    it('does not count an unusable filter as a filter', function () {
        // A URL that filters nothing must not light up the "N of M" count and the
        // Clear button, which together say "you are looking at part of the board".
        Livewire::withQueryParams(['tracker' => $this->tracker->public_id, 'health' => 'nonsense'])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertDontSee('Clear filters');
    });

    it('ignores a malformed date rather than failing', function () {
        Livewire::withQueryParams(['tracker' => $this->tracker->public_id, 'due_from' => 'not-a-date'])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSuccessful()
            ->assertSee('CCTV storage expansion');
    });

    it('says so when filters hide everything', function () {
        board()->set('search', 'nothing matches this')
            ->assertSee('None of the 3 cards on this board match');
    });

    it('clears every filter at once', function () {
        board()->set('search', 'UPS')->set('healthStates', ['at_risk'])->set('owners', [$this->joy->id])
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('healthStates', [])
            ->assertSet('owners', [])
            ->assertSee('CCTV storage expansion');
    });
});

describe('switching trackers', function () {

    it('keeps the tracker-agnostic filters', function () {
        // NFR-U2 — switching trackers must not lose the user's filters. Nobody wants to
        // retype a search because they looked at another board.
        board()->set('search', 'VPN')->set('healthStates', ['at_risk'])
            ->call('switchTracker', $this->other->public_id)
            ->assertSet('search', 'VPN')
            ->assertSet('healthStates', ['at_risk']);
    });

    it('drops a person the new tracker cannot show', function () {
        // joy is not a member of Systems Development, so the owner control there has no
        // option to represent them — the filter would go on hiding cards from a control
        // that shows nothing selected. A filter you cannot see is worse than one that
        // was dropped.
        board()->set('owners', [$this->joy->id])
            ->call('switchTracker', $this->other->public_id)
            ->assertSet('owners', []);
    });

    it('keeps the people the new tracker still has, rather than the whole filter or none of it', function () {
        // Now that a filter holds several names, "cannot be represented" is a question
        // per name. Dropping Ana because Dennis is not a member would be the old
        // single-value behaviour applied to a control that no longer has that excuse.
        app(TrackerService::class)->addMember($this->other, $this->nz, $this->admin);

        board()->set('owners', [$this->joy->id, $this->nz->id])
            ->call('switchTracker', $this->other->public_id)
            ->assertSet('owners', [$this->nz->id]);
    });

    it('does not resurrect a dropped person when switching back', function () {
        board()->set('assignees', [$this->nz->id])
            ->call('switchTracker', $this->other->public_id)
            ->call('switchTracker', $this->tracker->public_id)
            ->assertSet('assignees', []);
    });
});

/**
 * A pasted link is the other way a filter arrives, and it arrives without the bar
 * having had a chance to validate it.
 */
describe('links naming someone the board cannot show', function () {

    it('drops them on arrival rather than filtering by an invisible name', function () {
        // joy is not a member of Systems Development. Left in place this filters the
        // board down to nothing while every box in the owner menu shows unchecked —
        // the same failure switchTracker already guards against, arriving by URL.
        Livewire::withQueryParams([
            'tracker' => $this->other->public_id,
            'owner' => [(string) $this->joy->id],
        ])
            ->actingAs($this->admin)
            ->test(Board::class)
            ->assertSet('owners', []);
    });
});
