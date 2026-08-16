<?php

use App\Authorization\AccessContext;
use App\Enums\StepType;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\DueDatePrompt;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\StepService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The due-date gate: a step may refuse a card that has no due date.
 *
 * WHY A PER-STEP FLAG AND NOT A TYPE RULE — the thing these tests are really pinning
 * down. The rule people ask for is "Backlog → To Do must state a due date", and both
 * of those steps are `intake` in the default workflow. A rule phrased over step types
 * cannot express it, and a rule phrased over positions moves itself when an admin
 * reorders (FR-3.1). So the flag rides on the destination step, and the tests below
 * assert the three conditions that follow from that: destination flagged, step
 * actually changing, and no date already present.
 *
 * The ordering claim matters as much as the blocking one. project_step_movements is
 * the source of truth for every metric and recordMove resets the step clock, so a
 * refused drop must write NOTHING — several tests below check the movement count
 * rather than just the project's step_id, because a "moved then asked" implementation
 * would pass the second check and corrupt cycle time anyway.
 */
function gateAsUser(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

function gateSteps(Tracker $tracker, User $as)
{
    bindContextFor($as);

    return Step::where('tracker_id', $tracker->id)
        ->whereNull('archived_at')->orderBy('position')->get()->keyBy('name');
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->tracker = $svc->create(['name' => 'IT Technical'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $this->viewer = makeUser('ella@gmail.com', UserRole::Viewer);
    $svc->addMember($this->tracker, $this->member, $this->admin);
    $svc->addMember($this->tracker, $this->viewer, $this->admin);

    // "To Do" is deliberately intake, exactly like Backlog — the shape that makes a
    // type-based gate impossible. Placed after Backlog so the workflow reads
    // Backlog → To Do → In Progress → Done.
    $steps = gateSteps($this->tracker, $this->admin);

    $this->todo = app(StepService::class)->create($this->tracker, [
        'name' => 'To Do',
        'type' => StepType::Intake,
        'requires_due_date' => true,
    ], $this->admin, $steps['Backlog']->id);

    $this->steps = gateSteps($this->tracker, $this->admin);

    // Created through the service's one-field path, so it has no target_date — which
    // is the state nearly every existing card is in (FR-4.1 keeps the column
    // nullable, and the seeders use exactly this call).
    $this->project = app(ProjectService::class)
        ->create($this->tracker, ['name' => 'CCTV storage expansion'], $this->admin);
});

describe('configuring the gate', function () {

    it('is off unless the admin asks for it', function () {
        $step = app(StepService::class)->create(
            $this->tracker, ['name' => 'Blocked', 'type' => StepType::Intake], $this->admin
        );

        expect($step->requires_due_date)->toBeFalse();
    });

    it('can be set when the step is created', function () {
        expect($this->todo->fresh()->requires_due_date)->toBeTrue();
    });

    it('toggles, and records who changed it', function () {
        $changed = app(StepService::class)
            ->setRequiresDueDate($this->steps['In Progress'], true, $this->admin);

        expect($changed)->toBeTrue()
            ->and($this->steps['In Progress']->fresh()->requires_due_date)->toBeTrue()
            ->and(DB::table('audit_logs')
                ->where('action', 'tracker.step_gate_changed')
                ->where('actor_user_id', $this->admin->id)
                ->exists())->toBeTrue();
    });

    it('writes nothing when the value is unchanged', function () {
        $before = DB::table('audit_logs')->where('action', 'tracker.step_gate_changed')->count();

        // Already true from beforeEach.
        $changed = app(StepService::class)->setRequiresDueDate($this->todo, true, $this->admin);

        expect($changed)->toBeFalse()
            ->and(DB::table('audit_logs')->where('action', 'tracker.step_gate_changed')->count())
            ->toBe($before);
    });

    /**
     * The flag is a policy about future drops. Turning it on must not reach back and
     * demand a date from cards already sitting in the column — they got there under
     * the old rule, and a blocking dialog in front of a card nobody moved would be a
     * worse answer than an old card with no due date.
     */
    it('does not retro-flag the projects already in the column', function () {
        $inProgress = $this->steps['In Progress'];

        app(ProjectService::class)->move($this->project, $inProgress, $this->admin);
        app(StepService::class)->setRequiresDueDate($inProgress, true, $this->admin);

        $fresh = $this->project->fresh();

        expect($fresh->step_id)->toBe($inProgress->id)
            ->and($fresh->target_date)->toBeNull();
    });

    it('refuses a non-admin over HTTP, and the flag does not change', function (UserRole $role) {
        $user = makeUser("{$role->value}@gmail.com", $role);
        app(TrackerService::class)->addMember($this->tracker, $user, $this->admin);
        resetContext();

        $this->actingAs($user)
            ->post(route('admin.trackers.steps.gate', [$this->tracker, $this->steps['In Progress']]), [
                'requires_due_date' => 1,
            ])
            ->assertForbidden();

        expect(gateSteps($this->tracker, $this->admin)['In Progress']->requires_due_date)->toBeFalse();
    })->with([UserRole::Manager, UserRole::Member, UserRole::Viewer]);

    it('lets an admin flip it over HTTP', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.gate', [$this->tracker, $this->todo]), [
                'requires_due_date' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(gateSteps($this->tracker, $this->admin)['To Do']->requires_due_date)->toBeFalse();
    });

    it('will not flag a step through another tracker\'s URL', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $foreign = gateSteps($other, $this->admin)['In Progress'];
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.gate', [$this->tracker, $foreign]), [
                'requires_due_date' => 1,
            ])
            ->assertNotFound();

        expect(gateSteps($other, $this->admin)['In Progress']->requires_due_date)->toBeFalse();
    });
});

describe('dropping a card into a gated step', function () {

    it('refuses the move and asks for a date', function () {
        $before = DB::table('project_step_movements')->where('project_id', $this->project->id)->count();

        gateAsUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->todo->id)
            ->assertHasNoErrors()
            ->assertDispatched('due-date-prompt:open');

        // Nothing written. The movement count is the assertion that matters: a
        // move-then-ask implementation would leave the project's step_id correct and
        // the step clock reset, which is the corruption this ordering prevents.
        expect($this->project->fresh()->step_id)->toBe($this->steps['Backlog']->id)
            ->and(DB::table('project_step_movements')->where('project_id', $this->project->id)->count())
            ->toBe($before);
    });

    it('lets the card through once it has a due date', function () {
        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);

        gateAsUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->todo->id)
            ->assertNotDispatched('due-date-prompt:open');

        expect($this->project->fresh()->step_id)->toBe($this->todo->id);
    });

    it('does not gate a reorder inside the gated column', function () {
        // Get it in there legitimately first, then take the date away — the state an
        // older card reaches when it was moved before the gate existed.
        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);
        app(ProjectService::class)->move($this->project, $this->todo, $this->admin);
        app(ProjectService::class)->update($this->project, ['target_date' => null], $this->admin);

        $neighbour = app(ProjectService::class)->create($this->tracker, ['name' => 'Wi-Fi survey'], $this->admin);
        app(ProjectService::class)->update($neighbour, ['target_date' => '2026-10-01'], $this->admin);
        app(ProjectService::class)->move($neighbour, $this->todo, $this->admin);

        // Same step, different position. Gating this would make a flagged column
        // impossible to tidy.
        gateAsUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->todo->id, $neighbour->id)
            ->assertNotDispatched('due-date-prompt:open');
    });

    it('never gates a move into an unflagged step', function () {
        gateAsUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->steps['In Progress']->id)
            ->assertNotDispatched('due-date-prompt:open');

        expect($this->project->fresh()->step_id)->toBe($this->steps['In Progress']->id);
    });

    /**
     * Retreat is not a commitment. The flag is about ENTERING a column, so dragging
     * something back to an unflagged Backlog must not demand a date it never had.
     */
    it('lets an undated card be dragged back out of a gated column', function () {
        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);
        app(ProjectService::class)->move($this->project, $this->todo, $this->admin);
        app(ProjectService::class)->update($this->project, ['target_date' => null], $this->admin);

        gateAsUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->steps['Backlog']->id)
            ->assertNotDispatched('due-date-prompt:open');

        expect($this->project->fresh()->step_id)->toBe($this->steps['Backlog']->id);
    });

    /**
     * Authorization comes first. A Viewer must be stopped by the policy, not by the
     * gate — otherwise a role that may not move anything would be handed a dialog
     * inviting it to try.
     */
    it('refuses a viewer before the gate is ever consulted', function () {
        gateAsUser($this->viewer)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->todo->id)
            ->assertForbidden()
            ->assertNotDispatched('due-date-prompt:open');
    });

    it('gates a member exactly as it gates an admin', function () {
        gateAsUser($this->member)->test(Board::class)
            ->call('moveProject', $this->project->public_id, $this->todo->id)
            ->assertDispatched('due-date-prompt:open');

        expect($this->project->fresh()->step_id)->toBe($this->steps['Backlog']->id);
    });

    it('marks the gated column on the board so the rule is visible before the drag', function () {
        // Asserted on the tooltip, not on the badge's three letters: "due" appears in
        // enough of this page that a bare assertSee would pass without the badge
        // rendering at all.
        gateAsUser($this->member)->test(Board::class)
            ->assertSee('A project needs a due date before it can be moved into To Do.')
            ->assertDontSee('A project needs a due date before it can be moved into In Progress.');
    });
});

describe('the prompt', function () {

    it('completes the move it was opened for', function () {
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->set('dueDate', '2026-09-30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('due-date-prompt:saved');

        $fresh = $this->project->fresh();

        // The date is recorded AND the card has not moved yet — the board does that
        // when it handles the dispatched event.
        expect($fresh->target_date->toDateString())->toBe('2026-09-30')
            ->and($fresh->step_id)->toBe($this->steps['Backlog']->id);
    });

    it('opens with an empty field rather than a plausible default', function () {
        // A prefilled date can be accepted with one click, which is exactly the
        // outcome the gate exists to prevent: a board full of dates nobody agreed to,
        // with every lateness figure computed against them.
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->assertSet('dueDate', '');
    });

    it('requires a date', function () {
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->call('save')
            ->assertHasErrors(['dueDate' => 'required'])
            ->assertNotDispatched('due-date-prompt:saved');

        expect($this->project->fresh()->target_date)->toBeNull();
    });

    it('accepts a date in the past, because work is often already late', function () {
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->set('dueDate', now()->subWeek()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        expect($this->project->fresh()->target_date)->not->toBeNull();
    });

    it('leaves the card alone when cancelled', function () {
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->call('close')
            ->assertSet('open', false);

        $fresh = $this->project->fresh();

        expect($fresh->step_id)->toBe($this->steps['Backlog']->id)
            ->and($fresh->target_date)->toBeNull();
    });

    /**
     * The dialog being open is not evidence of anything. A crafted Livewire payload
     * reaches save() directly, so the policy has to be re-checked there.
     */
    it('re-checks the move policy on save', function () {
        gateAsUser($this->viewer)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->set('dueDate', '2026-09-30')
            ->call('save')
            ->assertForbidden();

        expect($this->project->fresh()->target_date)->toBeNull();
    });

    it('cannot be pointed at a project in a tracker the user cannot see', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $hidden = app(ProjectService::class)->create($other, ['name' => 'Payroll phase 2'], $this->admin);

        // Resolves through the visibility scope, so the project simply is not there:
        // the dialog renders nothing rather than leaking a name from another tracker.
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $hidden->public_id, $this->todo->id, null)
            ->assertDontSee('Payroll phase 2');
    });

    /**
     * Through ProjectService::update, not a direct write — so the change lands in the
     * activity feed. That record is the control standing in for a permission lock
     * here, and a bespoke forceFill would have quietly removed it.
     */
    it('records the date as attributed activity', function () {
        gateAsUser($this->member)->test(DueDatePrompt::class)
            ->call('openFor', $this->project->public_id, $this->todo->id, null)
            ->set('dueDate', '2026-09-30')
            ->call('save');

        expect(DB::table('project_activities')
            ->where('project_id', $this->project->id)
            ->where('type', 'field_edit')
            ->where('user_id', $this->member->id)
            ->exists())->toBeTrue();
    });
});

describe('the board resuming a gated move', function () {

    it('moves the card once the date exists', function () {
        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);

        gateAsUser($this->member)->test(Board::class)
            ->call('resumeMove', $this->project->public_id, $this->todo->id, null)
            ->assertHasNoErrors();

        expect($this->project->fresh()->step_id)->toBe($this->todo->id);
    });

    /**
     * The resume path is the drop path, so it cannot be a way around either the
     * policy or the gate itself. Without a date, re-entering moveProject must refuse
     * again rather than fall through.
     */
    it('still refuses when the date is somehow still missing', function () {
        gateAsUser($this->member)->test(Board::class)
            ->call('resumeMove', $this->project->public_id, $this->todo->id, null)
            ->assertDispatched('due-date-prompt:open');

        expect($this->project->fresh()->step_id)->toBe($this->steps['Backlog']->id);
    });

    it('still refuses a viewer', function () {
        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);

        gateAsUser($this->viewer)->test(Board::class)
            ->call('resumeMove', $this->project->public_id, $this->todo->id, null)
            ->assertForbidden();

        expect($this->project->fresh()->step_id)->toBe($this->steps['Backlog']->id);
    });

    /**
     * The drop position travels through the prompt and back. Without it, a card
     * dropped between two others would silently land at the bottom of the column on
     * the second attempt — the user's aim would be discarded by the dialog.
     */
    it('honours the position the card was dropped at', function () {
        $first = app(ProjectService::class)->create($this->tracker, ['name' => 'First'], $this->admin);
        $second = app(ProjectService::class)->create($this->tracker, ['name' => 'Second'], $this->admin);

        foreach ([$first, $second] as $sibling) {
            app(ProjectService::class)->update($sibling, ['target_date' => '2026-10-01'], $this->admin);
            app(ProjectService::class)->move($sibling, $this->todo, $this->admin);
        }

        app(ProjectService::class)->update($this->project, ['target_date' => '2026-09-30'], $this->admin);

        gateAsUser($this->member)->test(Board::class)
            ->call('resumeMove', $this->project->public_id, $this->todo->id, $first->id);

        $order = Project::where('step_id', $this->todo->id)
            ->orderBy('board_position')->pluck('name')->all();

        expect($order)->toBe(['First', 'CCTV storage expansion', 'Second']);
    });
});
