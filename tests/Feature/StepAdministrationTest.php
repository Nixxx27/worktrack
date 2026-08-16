<?php

use App\Enums\StepType;
use App\Enums\UserRole;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\StepService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;

/**
 * FR-3.1 — admins add steps to a tracker's workflow.
 *
 * The HTTP tests here matter as much as the service ones: the route is the only
 * place the capability gate and the per-tracker policy meet, and a service test
 * cannot prove either of them is wired.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
});

/**
 * Reads the board order as an admin would.
 *
 * Deliberately NOT SystemContext: that bypass refuses to run once a request has
 * established a user session, and half these tests read the steps back after an HTTP
 * call. Binding the admin context is also the more honest assertion — it proves the
 * step is visible through the same scope the board queries through.
 */
function liveSteps(Tracker $tracker, User $as)
{
    bindContextFor($as);

    return Step::where('tracker_id', $tracker->id)
        ->whereNull('archived_at')->orderBy('position')->get();
}

describe('creating a step', function () {

    it('appends to the end of the workflow when told to follow the last step', function () {
        $last = liveSteps($this->tracker, $this->admin)->last();

        app(StepService::class)->create(
            $this->tracker, ['name' => 'Released', 'type' => StepType::Terminal], $this->admin, $last->id
        );

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Done', 'Released']);
    });

    it('inserts in the middle without disturbing the surrounding order', function () {
        $inProgress = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'In Progress');

        app(StepService::class)->create(
            $this->tracker, ['name' => 'Testing', 'type' => StepType::Active], $this->admin, $inProgress->id
        );

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Testing', 'Done']);
    });

    it('places the step first when it follows nothing', function () {
        app(StepService::class)->create(
            $this->tracker, ['name' => 'Triage', 'type' => StepType::Intake], $this->admin, null
        );

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Triage', 'Backlog', 'New', 'In Progress', 'Done']);
    });

    it('leaves positions contiguous from zero so a later insert has no gap to trip over', function () {
        $steps = liveSteps($this->tracker, $this->admin);

        app(StepService::class)->create(
            $this->tracker, ['name' => 'Testing', 'type' => StepType::Active], $this->admin, $steps->first()->id
        );

        expect(liveSteps($this->tracker, $this->admin)->pluck('position')->map(fn ($p) => (int) $p)->all())
            ->toBe([0, 1, 2, 3, 4]);
    });

    it('appends rather than failing when the chosen predecessor no longer exists', function () {
        app(StepService::class)->create(
            $this->tracker, ['name' => 'Testing', 'type' => StepType::Active], $this->admin, 99999
        );

        expect(liveSteps($this->tracker, $this->admin)->last()->name)->toBe('Testing');
    });

    it('records the creation in the audit log against the tracker', function () {
        app(StepService::class)->create(
            $this->tracker, ['name' => 'Testing', 'type' => StepType::Active], $this->admin
        );

        $row = DB::table('audit_logs')->where('action', 'tracker.step_created')->first();

        expect($row)->not->toBeNull()
            ->and((int) $row->tracker_id)->toBe($this->tracker->id)
            ->and((int) $row->actor_user_id)->toBe($this->admin->id)
            ->and(json_decode($row->context, true)['name'])->toBe('Testing');
    });

    it('refuses to add a step to an archived tracker', function () {
        app(TrackerService::class)->archive($this->tracker, $this->admin);

        expect(fn () => app(StepService::class)->create(
            $this->tracker->fresh(), ['name' => 'Testing', 'type' => StepType::Active], $this->admin
        ))->toThrow(InvalidArgumentException::class);
    });
});

/**
 * Every test below calls resetContext() immediately before its HTTP request.
 *
 * Without it the request inherits the context this file's setup bound and never has
 * to establish its own — which is how a 500 on every {tracker} route survived a green
 * suite once already. Note the ordering: any liveSteps() call rebinds, so it has to
 * happen before the reset, not inside the request arguments.
 */
describe('reordering steps', function () {

    it('swaps a step with its neighbour', function () {
        $steps = liveSteps($this->tracker, $this->admin);

        app(StepService::class)->move($steps->firstWhere('name', 'Done'), 'up', $this->admin);

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'Done', 'In Progress']);
    });

    it('moves a step later', function () {
        $steps = liveSteps($this->tracker, $this->admin);

        app(StepService::class)->move($steps->firstWhere('name', 'Backlog'), 'down', $this->admin);

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['New', 'Backlog', 'In Progress', 'Done']);
    });

    it('is a no-op at either end rather than an error', function (string $name, string $direction) {
        $step = liveSteps($this->tracker, $this->admin)->firstWhere('name', $name);

        expect(app(StepService::class)->move($step, $direction, $this->admin))->toBeFalse()
            ->and(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Done']);
    })->with([['Backlog', 'up'], ['Done', 'down']]);

    it('keeps positions contiguous so the next move is unambiguous', function () {
        $steps = liveSteps($this->tracker, $this->admin);
        app(StepService::class)->move($steps->firstWhere('name', 'In Progress'), 'up', $this->admin);

        expect(liveSteps($this->tracker, $this->admin)->pluck('position')->map(fn ($p) => (int) $p)->all())
            ->toBe([0, 1, 2, 3]);
    });

    it('changes where new projects enter, because creation uses the first step', function () {
        $steps = liveSteps($this->tracker, $this->admin);
        app(StepService::class)->move($steps->firstWhere('name', 'New'), 'up', $this->admin);

        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);

        expect($project->step_id)->toBe($steps->firstWhere('name', 'New')->id);
    });

    it('records the move in the audit log', function () {
        $steps = liveSteps($this->tracker, $this->admin);
        app(StepService::class)->move($steps->firstWhere('name', 'Done'), 'up', $this->admin);

        $context = json_decode(DB::table('audit_logs')->where('action', 'tracker.step_moved')->value('context'), true);

        expect($context['name'])->toBe('Done')
            ->and($context['from_position'])->toBe(3)
            ->and($context['to_position'])->toBe(2);
    });

    it('reorders over HTTP and the board redraws in the new order', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.move', [$this->tracker, $done]), ['direction' => 'up'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'Done', 'In Progress']);
    });

    it('rejects a direction that is not up or down', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.move', [$this->tracker, $done]), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');
    });

    it('404s when the step belongs to a different tracker than the URL claims', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $foreign = liveSteps($other, $this->admin)->last();
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.move', [$this->tracker, $foreign]), ['direction' => 'up'])
            ->assertNotFound();

        expect(liveSteps($other, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Done']);
    });

    it('refuses a manager', function () {
        $manager = makeUser('manager@gmail.com', UserRole::Manager);
        app(TrackerService::class)->addMember($this->tracker, $manager, $this->admin);
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($manager)
            ->post(route('admin.trackers.steps.move', [$this->tracker, $done]), ['direction' => 'up'])
            ->assertForbidden();

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Done']);
    });
});

describe('renaming a step', function () {

    it('changes the name and nothing else about the step', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');

        expect(app(StepService::class)->rename($done, 'Shipped', $this->admin))->toBeTrue();

        $renamed = liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id);

        expect($renamed->name)->toBe('Shipped')
            ->and($renamed->type)->toBe(StepType::Terminal)
            ->and((int) $renamed->position)->toBe((int) $done->position);
    });

    it('leaves the workflow order untouched', function () {
        $steps = liveSteps($this->tracker, $this->admin);
        app(StepService::class)->rename($steps->firstWhere('name', 'New'), 'Triaged', $this->admin);

        expect(liveSteps($this->tracker, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'Triaged', 'In Progress', 'Done']);
    });

    it('trims surrounding whitespace rather than storing it', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        app(StepService::class)->rename($done, '  Shipped  ', $this->admin);

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id)->name)->toBe('Shipped');
    });

    it('reports no change and writes no audit row when the name is the same', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');

        expect(app(StepService::class)->rename($done, 'Done', $this->admin))->toBeFalse()
            ->and(DB::table('audit_logs')->where('action', 'tracker.step_renamed')->count())->toBe(0);
    });

    it('records both names in the audit log', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        app(StepService::class)->rename($done, 'Shipped', $this->admin);

        $context = json_decode(DB::table('audit_logs')->where('action', 'tracker.step_renamed')->value('context'), true);

        expect($context['from'])->toBe('Done')
            ->and($context['to'])->toBe('Shipped')
            ->and($context['step_id'])->toBe($done->id);
    });

    /**
     * The distinction that makes renaming safe where retyping is not (FR-3.7,
     * ARCHITECTURE §to_step_type): reports group by the frozen type, so relabelling a
     * column cannot move a project between metric families after the fact.
     */
    it('does not touch the frozen step type history is measured from', function () {
        $backlog = liveSteps($this->tracker, $this->admin)->first();
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);

        app(StepService::class)->rename($backlog, 'Inbox', $this->admin);

        expect(DB::table('project_step_movements')->where('project_id', $project->id)->value('to_step_type'))
            ->toBe($backlog->type->value);
    });

    it('refuses to rename an archived step', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        $done->forceFill(['archived_at' => now()])->save();

        expect(fn () => app(StepService::class)->rename($done, 'Shipped', $this->admin))
            ->toThrow(InvalidArgumentException::class);
    });

    it('renames over HTTP and the board redraws under the new name', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $done]), ['name' => 'Shipped'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        resetContext();
        $this->actingAs($this->admin)->get(route('board'))->assertSee('Shipped')->assertDontSee('>Done<', false);
    });

    it('rejects a name another live step already has, in that step own error bag', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $done]), ['name' => 'In Progress'])
            ->assertSessionHasErrors('name', errorBag: "step_rename_{$done->id}");

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id)->name)->toBe('Done');
    });

    /** The uniqueness rule ignores the step itself, or every no-op save would be an error. */
    it('accepts a submit that keeps the current name', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $done]), ['name' => 'Done'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    /** Archived names are out of the unique index, so they are reusable — DD-15. */
    it('allows reusing the name of an archived step', function () {
        $steps = liveSteps($this->tracker, $this->admin);
        $steps->firstWhere('name', 'New')->forceFill(['archived_at' => now()])->save();
        $done = $steps->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $done]), ['name' => 'New'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id)->name)->toBe('New');
    });

    it('404s when the step belongs to a different tracker than the URL claims', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $foreign = liveSteps($other, $this->admin)->last();
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $foreign]), ['name' => 'Shipped'])
            ->assertNotFound();

        expect(liveSteps($other, $this->admin)->pluck('name')->all())
            ->toBe(['Backlog', 'New', 'In Progress', 'Done']);
    });

    it('refuses every non-admin role', function (UserRole $role) {
        $user = makeUser("{$role->value}@gmail.com", $role);
        app(TrackerService::class)->addMember($this->tracker, $user, $this->admin);
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($user)
            ->post(route('admin.trackers.steps.rename', [$this->tracker, $done]), ['name' => 'Shipped'])
            ->assertForbidden();

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id)->name)->toBe('Done');
    })->with([UserRole::Manager, UserRole::Member, UserRole::Viewer]);

    it('exposes the name as an editable field on the admin screen', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->get(route('admin.trackers.index'))
            ->assertOk()
            ->assertSee(route('admin.trackers.steps.rename', [$this->tracker, $done]), false)
            ->assertSee('Rename Done');
    });
});

/**
 * FR-3.1 — retyping a step, the one edit that changes how work is MEASURED.
 *
 * Two invariants pull against each other here and both have to hold: history stays
 * exactly as it was measured, and `projects.current_step_type` — which is a mirror of
 * the present, not a record of the past — stops being a lie the moment the type
 * changes. Most of this block is about the seam between them.
 */
describe('retyping a step', function () {

    it('changes what the column means', function () {
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');

        expect(app(StepService::class)->retype($new, StepType::Active, $this->admin))->toBeTrue();

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $new->id)->type)
            ->toBe(StepType::Active);
    });

    it('reports no change and writes no audit row when the type is the same', function () {
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');
        $before = DB::table('audit_logs')->count();

        expect(app(StepService::class)->retype($new, StepType::Intake, $this->admin))->toBeFalse();
        expect(DB::table('audit_logs')->count())->toBe($before);
    });

    it('re-stamps the cards sitting in the column, because that mirror is read as authoritative', function () {
        // StallThresholdResolver gives intake more patience, the nightly sweep and the
        // tracker-archive count both filter on it, and in-flight metrics classify by it.
        // A stale mirror is not caution, it is a false statement about where work is.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();

        expect($project->fresh()->current_step_type)->toBe(StepType::Intake);

        app(StepService::class)->retype($backlog, StepType::Active, $this->admin);

        expect($project->fresh()->current_step_type)->toBe(StepType::Active);
    });

    it('re-stamps archived cards too, so a restore does not bring back a stale type', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Old work'], $this->admin);
        app(ProjectService::class)->archive($project, $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();

        app(StepService::class)->retype($backlog, StepType::Active, $this->admin);

        expect(DB::table('projects')->where('id', $project->id)->value('current_step_type'))->toBe('active');
    });

    it('leaves the frozen movement history exactly as it was measured', function () {
        // The whole reason retyping was held back. A figure that changes because an
        // admin edited a dropdown is not a measurement, so a project that crossed this
        // column while it was intake keeps the type it crossed under.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();

        app(StepService::class)->retype($backlog, StepType::Active, $this->admin);

        expect(DB::table('project_step_movements')->where('project_id', $project->id)->value('to_step_type'))
            ->toBe('intake');
    });

    it('does not invent a moment work started', function () {
        // Deliberate: stamping now() would fabricate a start, and backdating to the
        // card's arrival would claim knowledge the system never had — that time was
        // recorded as waiting. The card stays uncounted, and the dashboard says so.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();

        app(StepService::class)->retype($backlog, StepType::Active, $this->admin);

        expect($project->fresh()->first_active_at)->toBeNull();
    });

    it('records the change and its blast radius in the audit log', function () {
        app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();

        app(StepService::class)->retype($backlog, StepType::Active, $this->admin);

        $context = json_decode(
            DB::table('audit_logs')->where('action', 'tracker.step_retyped')->value('context'), true
        );

        expect($context['from'])->toBe('intake')
            ->and($context['to'])->toBe('active')
            ->and($context['projects_restamped'])->toBe(1);
    });

    describe('FR-3.3 — the two types a tracker cannot be left without', function () {

        it('refuses to retype the only active step', function () {
            // Without an active step there is no "first worked" moment, so cycle time
            // becomes uncomputable for everything the tracker does from here on.
            $inProgress = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'In Progress');

            expect(fn () => app(StepService::class)->retype($inProgress, StepType::Intake, $this->admin))
                ->toThrow(InvalidArgumentException::class);
        });

        it('refuses to retype the only terminal step', function () {
            $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');

            expect(fn () => app(StepService::class)->retype($done, StepType::Active, $this->admin))
                ->toThrow(InvalidArgumentException::class);
        });

        it('allows it once a second step of that type exists', function () {
            app(StepService::class)->create(
                $this->tracker, ['name' => 'Released', 'type' => StepType::Terminal], $this->admin
            );
            $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');

            expect(app(StepService::class)->retype($done, StepType::Active, $this->admin))->toBeTrue();
        });

        it('does not count an archived step as the second one', function () {
            // An archived Released column cannot receive work, so leaning on it to
            // satisfy the invariant would leave the tracker unable to finish anything.
            $released = app(StepService::class)->create(
                $this->tracker, ['name' => 'Released', 'type' => StepType::Terminal], $this->admin
            );
            $released->forceFill(['archived_at' => now()])->save();
            $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');

            expect(fn () => app(StepService::class)->retype($done, StepType::Active, $this->admin))
                ->toThrow(InvalidArgumentException::class);
        });

        it('never blocks retyping an intake step, which no tracker is required to have', function () {
            // Work simply starting in progress is unusual but coherent, unlike a tracker
            // that cannot measure or cannot finish.
            $this->tracker->steps()->where('name', 'New')->delete();
            $backlog = liveSteps($this->tracker, $this->admin)->first();

            expect(app(StepService::class)->retype($backlog, StepType::Active, $this->admin))->toBeTrue();
        });
    });

    it('refuses to retype an archived step', function () {
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        $done->forceFill(['archived_at' => now()])->save();

        expect(fn () => app(StepService::class)->retype($done, StepType::Active, $this->admin))
            ->toThrow(InvalidArgumentException::class);
    });

    it('retypes over HTTP and the board legend dot changes with it', function () {
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $new]), ['type' => 'active'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'counts toward cycle time'));

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $new->id)->type)
            ->toBe(StepType::Active);
    });

    it('tells the admin how many cards it reclassified underneath them', function () {
        app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall upgrade'], $this->admin);
        $backlog = liveSteps($this->tracker, $this->admin)->first();
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $backlog]), ['type' => 'active'])
            ->assertSessionHas('status', fn (string $s) => str_contains($s, '1 project(s)'));
    });

    it('refuses the last terminal step with a field error, not a 500', function () {
        // Reachable from the UI by picking a different type for the only Done column,
        // so it has to be an ordinary validation failure.
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $done]), ['type' => 'active'])
            ->assertSessionHasErrors('type', errorBag: "step_type_{$done->id}");

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $done->id)->type)
            ->toBe(StepType::Terminal);
    });

    it('accepts a submit that keeps the current type', function () {
        // The select posts on change, and "changed back to what it was" must not read
        // as an error — nor as the last-of-its-type refusal, which only applies to a
        // type that is actually moving.
        $done = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Done');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $done]), ['type' => 'terminal'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('rejects a type outside the three that drive metrics', function () {
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $new]), ['type' => 'archived'])
            ->assertSessionHasErrors('type', errorBag: "step_type_{$new->id}");
    });

    it('404s when the step belongs to a different tracker than the URL claims', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $foreign = liveSteps($other, $this->admin)->firstWhere('name', 'New');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $foreign]), ['type' => 'active'])
            ->assertNotFound();

        expect(liveSteps($other, $this->admin)->firstWhere('id', $foreign->id)->type)->toBe(StepType::Intake);
    });

    it('refuses every non-admin role', function (UserRole $role) {
        $user = makeUser("{$role->value}@gmail.com", $role);
        app(TrackerService::class)->addMember($this->tracker, $user, $this->admin);
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');
        resetContext();

        $this->actingAs($user)
            ->post(route('admin.trackers.steps.retype', [$this->tracker, $new]), ['type' => 'active'])
            ->assertForbidden();

        expect(liveSteps($this->tracker, $this->admin)->firstWhere('id', $new->id)->type)->toBe(StepType::Intake);
    })->with([UserRole::Manager, UserRole::Member, UserRole::Viewer]);

    it('exposes the type as an editable control on the admin screen', function () {
        $new = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'New');
        resetContext();

        $this->actingAs($this->admin)
            ->get(route('admin.trackers.index'))
            ->assertOk()
            ->assertSee(route('admin.trackers.steps.retype', [$this->tracker, $new]), false)
            ->assertSee('Step type for New');
    });
});

describe('the add-step endpoint', function () {

    it('lets an admin add a step and shows it on the board', function () {
        $inProgress = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'In Progress');
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Testing',
                'type' => 'active',
                'after_step_id' => $inProgress->id,
                'wip_limit' => 3,
            ])
            ->assertRedirect();

        $step = liveSteps($this->tracker, $this->admin)->firstWhere('name', 'Testing');

        expect($step->type)->toBe(StepType::Active)
            ->and((int) $step->wip_limit)->toBe(3);

        resetContext();
        $this->actingAs($this->admin)->get(route('board'))->assertSee('Testing');
    });

    it('rejects a duplicate live step name with a field error, not a database exception', function () {
        resetContext();

        $response = $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Done',
                'type' => 'terminal',
            ]);

        $response->assertSessionHasErrors('name', errorBag: "step_{$this->tracker->id}");
        expect(liveSteps($this->tracker, $this->admin))->toHaveCount(4);
    });

    it('rejects a step type outside the three that drive metrics', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Blocked',
                'type' => 'waiting',
            ])
            ->assertSessionHasErrors('type', errorBag: "step_{$this->tracker->id}");
    });

    it('ignores a predecessor belonging to another tracker instead of confirming it exists', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $foreign = liveSteps($other, $this->admin)->first();
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Testing',
                'type' => 'active',
                'after_step_id' => $foreign->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Appended, and — the point of the test — no step was added to the other tracker.
        expect(liveSteps($this->tracker, $this->admin)->last()->name)->toBe('Testing')
            ->and(liveSteps($other, $this->admin))->toHaveCount(4);
    });

    it('refuses every non-admin role', function (UserRole $role) {
        $user = makeUser("{$role->value}@gmail.com", $role);
        app(TrackerService::class)->addMember($this->tracker, $user, $this->admin);
        resetContext();

        $this->actingAs($user)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Testing',
                'type' => 'active',
            ])
            ->assertForbidden();

        expect(liveSteps($this->tracker, $this->admin))->toHaveCount(4);
    })->with([UserRole::Manager, UserRole::Member, UserRole::Viewer]);

    it('lists each tracker current steps on the admin screen', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->get(route('admin.trackers.index'))
            ->assertOk()
            ->assertSee('Steps')
            ->assertSee('In Progress')
            ->assertSee('Add step');
    });

    /**
     * Step configuration lives in tracker settings, not on the board. The board is a
     * working surface — it should not carry an editing control that only one role can
     * use and that is used a handful of times in a tracker's life.
     */
    it('keeps step configuration off the board, even for an admin', function () {
        resetContext();

        $this->actingAs($this->admin)->get(route('board'))
            ->assertOk()
            ->assertDontSee('Add step');
    });
});
