<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use App\Livewire\ProjectDrawer;
use App\Models\Step;
use App\Models\User;
use App\Services\Metrics\MetricsRepository;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * FR-8 — the four metric families, and the isolation of every figure in them.
 */
function dashAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->itTracker = $svc->create(['name' => 'IT Technical'], $this->admin);
    $this->sdTracker = $svc->create(['name' => 'Systems Development'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $svc->addMember($this->itTracker, $this->member, $this->admin);

    $steps = fn ($tracker) => SystemContext::run(fn () => Step::where('tracker_id', $tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    $this->itSteps = $steps($this->itTracker);
    $this->sdSteps = $steps($this->sdTracker);

    $projects = app(ProjectService::class);
    $movements = app(MovementRecorder::class);

    // IT: one finished (so cycle time exists), one stuck in progress.
    $done = $projects->create($this->itTracker, ['name' => 'SPF hardening'], $this->admin);
    $movements->recordMove($done, $this->itSteps['In Progress'], $this->admin);
    $movements->recordMove($done->fresh(), $this->itSteps['Done'], $this->admin);

    $this->stuck = $projects->create($this->itTracker, ['name' => 'Core switch replacement'], $this->admin);
    $movements->recordMove($this->stuck, $this->itSteps['In Progress'], $this->admin);
    $this->stuck->fresh()->forceFill([
        'current_step_entered_at' => now()->subDays(23),
        'last_activity_at' => now()->subDays(23),
    ])->save();

    // SD: a project the member must never see in any figure.
    $this->sdProject = $projects->create($this->sdTracker, ['name' => 'Payroll phase 2'], $this->admin);
});

describe('metric figures respect tracker visibility', function () {

    it('excludes another tracker\'s projects from a member\'s aging list', function () {
        app(AccessContext::class)->forUser($this->member);

        $names = app(MetricsRepository::class)->aging(20)->pluck('name');

        expect($names)->toContain('Core switch replacement')
            // metrics-2 territory: an aggregate that leaks is silent, produces no error,
            // and would never surface in functional testing.
            ->and($names)->not->toContain('Payroll phase 2');
    });

    it('gives an admin the full picture', function () {
        app(AccessContext::class)->forUser($this->admin);

        expect(app(MetricsRepository::class)->aging(20)->pluck('name'))
            ->toContain('Payroll phase 2');
    });

    it('gives two viewers with different memberships different cache keys', function () {
        $repo = app(MetricsRepository::class);

        app(AccessContext::class)->forUser($this->member);
        $memberKey = $repo->scopeKey();

        app(AccessContext::class)->forUser($this->admin);

        expect($repo->scopeKey())->not->toBe($memberKey);
    });
});

describe('cycle time', function () {

    it('measures first work to completion and excludes in-flight projects', function () {
        app(AccessContext::class)->forUser($this->admin);

        $cycle = app(MetricsRepository::class)->cycleTime($this->itTracker->id);

        expect($cycle['completed_count'])->toBe(1)
            ->and($cycle['median_seconds'])->not->toBeNull()
            ->and($cycle['skipped_active_count'])->toBe(0);
    });

    it('reports skipped-work projects separately rather than as zero', function () {
        // Coercing a never-worked project to a zero-day cycle time would drag the median
        // down and look like an improvement.
        app(AccessContext::class)->forUser($this->admin);

        $skipped = app(ProjectService::class)->create($this->itTracker, ['name' => 'Mis-dragged'], $this->admin);
        app(MovementRecorder::class)->recordMove($skipped, $this->itSteps['Done'], $this->admin);

        $cycle = app(MetricsRepository::class)->cycleTime($this->itTracker->id);

        expect($cycle['skipped_active_count'])->toBe(1)
            ->and($cycle['completed_count'])->toBe(2);
    });

    it('reports the in-flight age distribution alongside it', function () {
        // M-D6 — the survivorship-bias correction. Median cycle time IMPROVES the longer
        // something is stuck, so this has to be available on the same screen.
        app(AccessContext::class)->forUser($this->admin);

        $inFlight = app(MetricsRepository::class)->inFlightAges($this->itTracker->id);

        expect($inFlight['count'])->toBeGreaterThan(0)
            ->and($inFlight['oldest_seconds'])->not->toBeNull();
    });
});

describe('bottleneck reporting', function () {

    it('groups by step type rather than step name', function () {
        // FR-3.7 / FR-8.8 — "In Progress" in one tracker is not comparable to
        // "Development" in another, so names must never be the grouping key.
        app(AccessContext::class)->forUser($this->admin);

        $byType = app(MetricsRepository::class)->averageSecondsByStepType();

        expect(array_keys($byType))->each->toBeIn(['intake', 'active', 'terminal']);
    });
});

describe('the dashboard renders', function () {

    it('shows an admin an all-trackers roll-up by default', function () {
        // FR-8.5 — the single pane of glass must not require visiting each board.
        dashAs($this->admin)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Where work is stuck')
            ->assertSee('Core switch replacement')
            ->assertSee('Payroll phase 2')
            ->assertSee('across all trackers')
            // FR-8.9 / FR-8.10 — the head-level panels, not just the flow metrics.
            ->assertSee('Past due and due soon')
            ->assertSee('Arrivals against completions')
            ->assertSee('Tracker scorecard');
    });

    it('labels a member\'s figures as partial', function () {
        dashAs($this->member)->test(Dashboard::class)
            ->assertOk()
            ->assertSee('within your trackers')
            ->assertDontSee('Payroll phase 2');
    });

    it('warns that median cycle time only counts finished work', function () {
        dashAs($this->admin)->test(Dashboard::class)
            ->assertSee('looks better the longer something stays stuck');
    });

    it('is reachable over real HTTP', function () {
        $this->actingAs($this->member)->get('/dashboard')->assertOk();
    });

    it('is not reachable by a pending account', function () {
        $pending = makeUser('waiting@gmail.com', UserRole::Member, UserStatus::Pending);

        $this->actingAs($pending)->get('/dashboard')->assertRedirect(route('auth.pending'));
    });
});

describe('deadline performance (FR-8.9)', function () {

    it('separates work that is already late from work that is merely due soon', function () {
        app(AccessContext::class)->forUser($this->admin);
        $projects = app(ProjectService::class);

        $projects->create($this->itTracker, ['name' => 'Overran', 'target_date' => now()->subDays(3)->toDateString()], $this->admin);
        $projects->create($this->itTracker, ['name' => 'This week', 'target_date' => now()->addDays(2)->toDateString()], $this->admin);
        $projects->create($this->itTracker, ['name' => 'Next quarter', 'target_date' => now()->addDays(60)->toDateString()], $this->admin);

        $deadlines = app(MetricsRepository::class)->deadlines($this->itTracker->id);

        expect($deadlines['overdue']->pluck('name'))->toContain('Overran')
            ->and($deadlines['overdue']->pluck('name'))->not->toContain('This week')
            ->and($deadlines['due_soon']->pluck('name'))->toContain('This week')
            // Beyond the horizon is not "due soon" — a list that includes everything
            // with a date is a project list, not a deadline report.
            ->and($deadlines['due_soon']->pluck('name'))->not->toContain('Next quarter');
    });

    it('counts in-flight work carrying no target date at all', function () {
        // Zero overdue can mean the team is on top of everything, or that nobody
        // promised anything. Without this figure the two are indistinguishable.
        app(AccessContext::class)->forUser($this->admin);

        $deadlines = app(MetricsRepository::class)->deadlines($this->itTracker->id);

        expect($deadlines['overdue'])->toBeEmpty()
            ->and($deadlines['without_target'])->toBeGreaterThan(0);
    });

    it('keeps another tracker\'s overdue work out of a member\'s figures', function () {
        app(AccessContext::class)->forUser($this->admin);
        app(ProjectService::class)->create(
            $this->sdTracker,
            ['name' => 'Payroll cutover', 'target_date' => now()->subDays(9)->toDateString()],
            $this->admin
        );

        app(AccessContext::class)->forUser($this->member);

        expect(app(MetricsRepository::class)->deadlines()['overdue']->pluck('name'))
            ->not->toContain('Payroll cutover');
    });

    it('tallies deadline pressure against the owner AND every assignee', function () {
        // The gap this closes: owner_user_id and project_assignees are written by
        // different code paths and nothing keeps them in step, so a table that showed
        // only the owner made collaborators on late work invisible.
        app(AccessContext::class)->forUser($this->admin);

        app(ProjectService::class)->create(
            $this->itTracker,
            [
                'name' => 'CCTV storage expansion',
                'target_date' => now()->subDays(8)->toDateString(),
                'assignees' => [$this->member->id],
            ],
            $this->admin
        );

        $byMember = app(MetricsRepository::class)->deadlines($this->itTracker->id)['by_member'];

        expect($byMember->firstWhere('id', $this->admin->id)['overdue'])->toBe(1)
            ->and($byMember->firstWhere('id', $this->member->id)['overdue'])->toBe(1);
    });

    it('counts a person once when they are both owner and assignee', function () {
        app(AccessContext::class)->forUser($this->admin);

        app(ProjectService::class)->create(
            $this->itTracker,
            [
                'name' => 'Firewall rule audit Q3',
                'target_date' => now()->subDays(2)->toDateString(),
                'owner_user_id' => $this->member->id,
                'assignees' => [$this->member->id],
            ],
            $this->admin
        );

        $byMember = app(MetricsRepository::class)->deadlines($this->itTracker->id)['by_member'];

        // Two rows for one person reads as two late projects. The dedup is keyed on
        // user id precisely so being named twice on one project stays one problem.
        expect($byMember->where('id', $this->member->id))->toHaveCount(1)
            ->and($byMember->firstWhere('id', $this->member->id)['overdue'])->toBe(1);
    });

    it('separates a person\'s late work from their merely-approaching work', function () {
        app(AccessContext::class)->forUser($this->admin);
        $projects = app(ProjectService::class);

        $projects->create($this->itTracker, [
            'name' => 'Already late',
            'target_date' => now()->subDays(4)->toDateString(),
            'owner_user_id' => $this->member->id,
        ], $this->admin);

        $projects->create($this->itTracker, [
            'name' => 'Coming up',
            'target_date' => now()->addDays(3)->toDateString(),
            'owner_user_id' => $this->member->id,
        ], $this->admin);

        $row = app(MetricsRepository::class)
            ->deadlines($this->itTracker->id)['by_member']
            ->firstWhere('id', $this->member->id);

        // Collapsing these into one figure would put someone with a week of runway
        // next to someone already past the date, which is the distinction the whole
        // panel exists to draw.
        expect($row['overdue'])->toBe(1)
            ->and($row['due_soon'])->toBe(1);
    });

    it('ranks the most overdue person first, ahead of anyone merely due soon', function () {
        app(AccessContext::class)->forUser($this->admin);
        $projects = app(ProjectService::class);

        $projects->create($this->itTracker, [
            'name' => 'Late one',
            'target_date' => now()->subDays(5)->toDateString(),
            'owner_user_id' => $this->member->id,
        ], $this->admin);

        foreach (['Soon A', 'Soon B', 'Soon C'] as $name) {
            $projects->create($this->itTracker, [
                'name' => $name,
                'target_date' => now()->addDays(2)->toDateString(),
            ], $this->admin);
        }

        $byMember = app(MetricsRepository::class)->deadlines($this->itTracker->id)['by_member'];

        // One overdue outranks three due-soon: the list is a chase order, not a
        // volume ranking, and sorting by the total would bury the actual fire.
        expect($byMember->first()['id'])->toBe($this->member->id);
    });

    it('names the gap rather than dropping work nobody is on', function () {
        app(AccessContext::class)->forUser($this->admin);

        $orphan = app(ProjectService::class)->create(
            $this->itTracker,
            ['name' => 'Nobody\'s problem', 'target_date' => now()->subDays(6)->toDateString()],
            $this->admin
        );

        // Owners can be cleared later — TrackerService nulls them when a member leaves.
        // An unowned overdue project silently missing from the tally is the failure
        // mode that lets work go unchased.
        $orphan->forceFill(['owner_user_id' => null])->save();

        $byMember = app(MetricsRepository::class)->deadlines($this->itTracker->id)['by_member'];

        expect($byMember->firstWhere('id', 0))->not->toBeNull()
            ->and($byMember->firstWhere('id', 0)['name'])->toBe('Unassigned')
            ->and($byMember->firstWhere('id', 0)['overdue'])->toBe(1);
    });

    it('keeps another tracker\'s late work out of a member\'s per-person tally', function () {
        app(AccessContext::class)->forUser($this->admin);

        $svc = app(TrackerService::class);
        $outsider = makeUser('outsider@gmail.com', UserRole::Member);
        $svc->addMember($this->sdTracker, $outsider, $this->admin);

        app(ProjectService::class)->create(
            $this->sdTracker,
            [
                'name' => 'Payroll cutover',
                'target_date' => now()->subDays(9)->toDateString(),
                'owner_user_id' => $outsider->id,
            ],
            $this->admin
        );

        app(AccessContext::class)->forUser($this->member);

        // The tally is derived from the same scoped query as the lists, so it inherits
        // TrackerVisibilityScope — but a per-person roll-up leaking a name is a worse
        // NFR-S3 breach than leaking a count, so it is asserted directly.
        expect(app(MetricsRepository::class)->deadlines()['by_member']->pluck('name'))
            ->not->toContain($outsider->name);
    });

    it('shows the assignee on a late project the owner does not carry alone', function () {
        app(AccessContext::class)->forUser($this->admin);

        app(ProjectService::class)->create(
            $this->itTracker,
            [
                'name' => 'Reporting API v2',
                'target_date' => now()->subDays(8)->toDateString(),
                'assignees' => [$this->member->id],
            ],
            $this->admin
        );

        dashAs($this->admin)->test(Dashboard::class)
            ->assertSee('Who is carrying it')
            ->assertSee($this->member->name);
    });

    it('measures delivery against the promised date and excludes work that carried no promise', function () {
        app(AccessContext::class)->forUser($this->admin);
        $projects = app(ProjectService::class);
        $movements = app(MovementRecorder::class);

        $finish = function (string $name, string $target) use ($projects, $movements) {
            $p = $projects->create($this->itTracker, ['name' => $name, 'target_date' => $target], $this->admin);
            $movements->recordMove($p, $this->itSteps['In Progress'], $this->admin);
            $movements->recordMove($p->fresh(), $this->itSteps['Done'], $this->admin);
        };

        $finish('Shipped early', now()->addDays(5)->toDateString());
        $finish('Shipped late', now()->subDays(5)->toDateString());

        $onTime = app(MetricsRepository::class)->onTimeDelivery($this->itTracker->id);

        expect($onTime['measured'])->toBe(2)
            ->and($onTime['on_time'])->toBe(1)
            ->and($onTime['late'])->toBe(1)
            ->and($onTime['rate'])->toBe(50)
            // 'SPF hardening' from the fixture finished without a target date. Counting
            // it as on time would push the rate towards 100% as the team got WORSE at
            // setting dates.
            ->and($onTime['unpromised'])->toBe(1);
    });

    it('reports no rate at all rather than a flattering one when nothing was promised', function () {
        app(AccessContext::class)->forUser($this->admin);

        expect(app(MetricsRepository::class)->onTimeDelivery($this->itTracker->id)['rate'])->toBeNull();
    });
});

describe('flow — arrivals against completions (FR-8.4)', function () {

    it('zero-fills quiet months so the axis cannot compress a gap away', function () {
        // A groupBy over rows alone omits empty months entirely, which puts March next
        // to June and draws a continuous line over the quarter nothing shipped in.
        app(AccessContext::class)->forUser($this->admin);

        $flow = app(MetricsRepository::class)->flowByMonth(6, $this->itTracker->id);

        expect($flow)->toHaveCount(6)
            ->and($flow->keys()->last())->toBe(now(config('worktrack.default_timezone'))->format('Y-m'))
            ->and($flow->first())->toHaveKeys(['arrived', 'completed']);
    });

    it('counts arrivals beside completions, so a growing queue cannot hide behind output', function () {
        app(AccessContext::class)->forUser($this->admin);

        $thisMonth = app(MetricsRepository::class)
            ->flowByMonth(6, $this->itTracker->id)
            ->last();

        // The fixture creates three IT projects and completes one of them.
        expect($thisMonth['arrived'])->toBeGreaterThan($thisMonth['completed'])
            ->and($thisMonth['completed'])->toBe(1);
    });
});

describe('portfolio shape and the tracker scorecard (FR-8.10)', function () {

    it('buckets in-flight work by days in its current step', function () {
        app(AccessContext::class)->forUser($this->admin);

        $buckets = app(MetricsRepository::class)->agingBuckets($this->itTracker->id);

        // 'Core switch replacement' was pushed to 23 days in step by the fixture.
        expect($buckets->pluck('label')->all())->toBe(['0–7d', '8–14d', '15–30d', '31d+'])
            ->and($buckets->firstWhere('label', '15–30d')['count'])->toBe(1)
            ->and($buckets->sum('count'))->toBe(app(MetricsRepository::class)->inFlightAges($this->itTracker->id)['count']);
    });

    it('gives one row per tracker the viewer can actually see', function () {
        app(AccessContext::class)->forUser($this->admin);

        expect(app(MetricsRepository::class)->trackerScorecard()->pluck('name'))
            ->toContain('IT Technical')
            ->toContain('Systems Development');

        app(AccessContext::class)->forUser($this->member);

        $memberRows = app(MetricsRepository::class)->trackerScorecard();

        // metrics-2 again: the comparison view is the easiest place to leak a tracker,
        // because a per-tracker count reads as harmless until it names a team the
        // viewer is not on.
        expect($memberRows->pluck('name'))->toContain('IT Technical')
            ->and($memberRows->pluck('name'))->not->toContain('Systems Development');
    });

    it('counts each tracker\'s stalled and in-flight work separately', function () {
        app(AccessContext::class)->forUser($this->admin);

        $it = app(MetricsRepository::class)->trackerScorecard()->firstWhere('name', 'IT Technical');

        expect($it['in_flight'])->toBe(1)
            ->and($it['completed'])->toBe(1)
            ->and($it['median_step_seconds'])->not->toBeNull();
    });
});

describe('the dashboard stays flat in the number of projects', function () {

    it('does not add a query per project as the portfolio grows', function () {
        // Eleven panels, each of which could plausibly have been written as a loop over
        // trackers or a lazy-loaded relation per row. This is the guard: the query count
        // must be a function of how many PANELS there are, not how much work exists.
        $projects = app(ProjectService::class);

        for ($i = 0; $i < 30; $i++) {
            $projects->create($this->itTracker, [
                'name' => 'Bulk '.$i,
                'target_date' => now()->addDays($i - 15)->toDateString(),
            ], $this->admin);
        }

        app(AccessContext::class)->forUser($this->admin);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        dashAs($this->admin)->test(Dashboard::class)->assertOk();

        expect($queries)->toBeLessThan(40);
    });
});

describe('the dashboard links through to the work (FR-8.11)', function () {

    it('opens the linked card and lands on the board it actually lives on', function () {
        // Without the tracker resolution this opens the alphabetically first board and
        // a drawer describing a project that is not on it.
        dashAs($this->admin)
            ->withQueryParams(['project' => $this->stuck->public_id])
            ->test(Board::class)
            ->assertSet('trackerId', $this->itTracker->public_id)
            ->assertSet('openProjectId', $this->stuck->public_id);
    });

    it('opens the drawer on first paint when mounted with a project', function () {
        dashAs($this->admin)
            ->test(ProjectDrawer::class, ['project' => $this->stuck->public_id])
            ->assertSet('open', true)
            ->assertSet('projectId', $this->stuck->public_id);
    });

    it('stays shut for a stale link rather than erroring the whole board', function () {
        // A bookmarked link can outlive the project it points at, and the board behind
        // the drawer is the correct fallback — not a 404 page.
        dashAs($this->admin)
            ->test(ProjectDrawer::class, ['project' => 'NOTAREALPUBLICID0000000000'])
            ->assertSet('open', false);
    });

    it('refuses to open a card from a tracker the viewer cannot see', function () {
        dashAs($this->member)
            ->test(ProjectDrawer::class, ['project' => $this->sdProject->public_id])
            ->assertSet('open', false)
            ->assertSet('projectId', null);
    });
});

describe('zero is not the same as null', function () {

    // Regression: Collection::filter() with no callback drops 0 as falsy, which
    // silently excluded fast work from every cycle-time figure — making a team that
    // ships quickly look like a team that skips process.
    it('counts a project completed in under a second as a real zero-duration cycle', function () {
        app(AccessContext::class)->forUser($this->admin);

        // Time frozen so the cycle is genuinely 0 seconds — the exact edge case that
        // a bare filter() silently discarded.
        $this->freezeTime();

        $fast = app(ProjectService::class)->create($this->itTracker, ['name' => 'Same-day fix'], $this->admin);
        app(MovementRecorder::class)->recordMove($fast, $this->itSteps['In Progress'], $this->admin);
        app(MovementRecorder::class)->recordMove($fast->fresh(), $this->itSteps['Done'], $this->admin);

        expect($fast->fresh()->cycle_time_seconds)->toBe(0);

        $cycle = app(MetricsRepository::class)->cycleTime($this->itTracker->id);

        expect($cycle['median_seconds'])->not->toBeNull()
            // It genuinely did the work, so it must NOT be counted as skipped.
            ->and($fast->fresh()->skipped_active)->toBeFalse();
    });
});
