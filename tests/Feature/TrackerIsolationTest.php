<?php

use App\Authorization\AccessContext;
use App\Authorization\Exceptions\MissingAccessContextException;
use App\Authorization\Exceptions\SystemContextUnavailableException;
use App\Authorization\SystemContext;
use App\Authorization\UserContext;
use App\Enums\StepType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The isolation boundary, tested at the application layer.
 *
 * The database half is covered by SchemaInvariantsTest. This file covers the half
 * that database constraints cannot: what a query RETURNS. A composite foreign key
 * stops you writing a cross-tracker row; only the scope stops you reading one.
 */
// makeUser / makeTracker / addMember / bindContextFor now live in tests/Pest.php, so
// every feature file can run on its own rather than only inside a full-suite run.

beforeEach(function () {
    $this->itTracker = makeTracker('IT Technical');
    $this->sdTracker = makeTracker('Systems Development');

    $this->bob = makeUser('bob@gmail.com');
    $this->amy = makeUser('amy@gmail.com');
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    $this->orphan = makeUser('orphan@gmail.com');   // approved, zero memberships (FR-1.10)

    addMember($this->itTracker, $this->bob);
    addMember($this->sdTracker, $this->amy);

    SystemContext::run(function () {
        foreach ([$this->itTracker, $this->sdTracker] as $tracker) {
            $step = Step::create([
                'tracker_id' => $tracker->id,
                'name' => 'Backlog',
                'type' => StepType::Intake,
                'position' => 0,
            ]);

            Project::create([
                'tracker_id' => $tracker->id,
                'step_id' => $step->id,
                'name' => $tracker->name.' project',
                'last_activity_at' => now(),
                'current_step_entered_at' => now(),
            ]);
        }
    });
});

describe('the Tracker model itself is scoped', function () {

    // ═══════════════════════════════════════════════════════════════════════════
    // REGRESSION TEST — VERIFICATION.md authorization-1.
    //
    // The original scope filtered on a hardcoded `tracker_id` column. `trackers` has
    // `id`, so Tracker could not be scoped at all — and the tracker switcher, written
    // the obvious way, listed every tracker in the company to a member of one.
    // Clicking one 404'd via the policy, so a route-based isolation sweep stayed
    // green while the names had already leaked.
    //
    // This is the single most direct test of FR-2.9 and US-1's third criterion.
    // ═══════════════════════════════════════════════════════════════════════════
    it('shows a member only the trackers they belong to', function () {
        bindContextFor($this->bob);

        expect(Tracker::pluck('name')->all())->toBe(['IT Technical']);
    });

    it('shows an approved user with zero memberships NOTHING', function () {
        bindContextFor($this->orphan);

        // FR-1.10 — the empty state must be genuinely empty, not "every tracker in
        // the company with a 404 waiting behind each one".
        expect(Tracker::count())->toBe(0);
    });

    it('shows an admin every tracker', function () {
        bindContextFor($this->admin);

        expect(Tracker::count())->toBe(2);
    });

    it('hides trackers from a suspended user even though they still hold membership', function () {
        $this->bob->update(['status' => UserStatus::Suspended]);
        bindContextFor($this->bob->fresh());

        // FR-1.7 — denial takes effect on the very next request, and the membership
        // row is irrelevant while the account is not active.
        expect(Tracker::count())->toBe(0);
    });
});

describe('tracker-owned models are scoped', function () {

    it('hides another tracker\'s projects', function () {
        bindContextFor($this->bob);

        expect(Project::pluck('name')->all())->toBe(['IT Technical project']);
    });

    it('hides another tracker\'s steps', function () {
        bindContextFor($this->amy);

        expect(Step::count())->toBe(1)
            ->and(Step::first()->tracker_id)->toBe($this->sdTracker->id);
    });

    it('returns nothing at all for a guest', function () {
        bindContextFor(null);

        expect(Project::count())->toBe(0)
            ->and(Tracker::count())->toBe(0);
    });
});

describe('the scope cannot be dissolved by caller conditions', function () {

    // VERIFICATION.md authorization-4 describes how appending an orWhere to a RAW
    // query builder dissolves the tracker predicate, because AND binds tighter than
    // OR. Eloquent's global scopes are protected against this — callScope() measures
    // the pre-existing where count and nests them. This test pins that protection so
    // nobody "optimises" a scoped read down to a raw builder without noticing.
    it('keeps the tracker predicate intact when a caller appends an orWhere', function () {
        bindContextFor($this->bob);

        $results = Project::where('name', 'like', '%nothing%')
            ->orWhere('name', 'like', '%project%')
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->tracker_id)->toBe($this->itTracker->id);
    });
});

describe('the context fails closed', function () {

    it('throws rather than returning rows when no context is bound', function () {
        app(AccessContext::class)->reset();

        // Returning an empty set here would be indistinguishable from a legitimate
        // empty result, and would hide the bug forever.
        expect(fn () => Project::count())->toThrow(MissingAccessContextException::class);
    });

    it('gives two users with different memberships different cache keys', function () {
        // VERIFICATION.md metrics-2: a dashboard cache keyed without the viewer's
        // tracker set serves one user's roll-up to another. Silent, and invisible to
        // functional testing.
        $bobKey = bindContextFor($this->bob)->cacheKey();
        $amyKey = bindContextFor($this->amy)->cacheKey();

        expect($bobKey)->not->toBe($amyKey);
    });
});

describe('SystemContext is not reachable from a user request', function () {

    // ═══════════════════════════════════════════════════════════════════════════
    // REGRESSION TEST — VERIFICATION.md authorization-3.
    //
    // The original guard was app()->runningInConsole(), which is TRUE for the entire
    // test suite because Pest runs under the CLI SAPI. The guard was therefore open
    // in every feature test that would have covered it.
    //
    // These two tests are the reason the latch asks "does this execution carry a
    // user session?" instead: that question has a real, different answer in each
    // environment, so both directions are testable without mocking anything.
    // ═══════════════════════════════════════════════════════════════════════════
    it('throws when called from a request carrying a session', function () {
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        expect(fn () => SystemContext::run(fn () => Tracker::count()))
            ->toThrow(SystemContextUnavailableException::class);
    });

    it('permits worker and console execution, which carry no session', function () {
        app()->instance('request', Request::create('/'));   // no session attached

        expect(SystemContext::run(fn () => Tracker::count()))->toBe(2);
    });

    it('restores the previous context afterwards, even when the callback throws', function () {
        bindContextFor($this->bob);

        try {
            SystemContext::run(fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        // Bob must not inherit system visibility because a job blew up.
        expect(Tracker::count())->toBe(1);
    });
});

describe('UserContext scopes background work to one user', function () {

    it('gives a queued job only that user\'s trackers', function () {
        // The alternative — wrapping a per-user job in SystemContext — is how a
        // Member's CSV export becomes a dump of every tracker in the company.
        $seen = UserContext::runAs($this->bob, fn () => Tracker::pluck('name')->all());

        expect($seen)->toBe(['IT Technical']);
    });
});

describe('tracker_id is immutable', function () {

    it('refuses to move a project to another tracker', function () {
        bindContextFor($this->admin);

        $project = Project::where('tracker_id', $this->itTracker->id)->first();

        // Settled decision (OQ12): moving projects between trackers is out of scope.
        // The composite FKs would reject it anyway (errno 1451) — this guard just
        // fails earlier with a readable message.
        expect(fn () => $project->update(['tracker_id' => $this->sdTracker->id]))
            ->toThrow(LogicException::class);
    });
});
