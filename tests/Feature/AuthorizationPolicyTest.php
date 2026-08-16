<?php

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Authorization\SystemContext;
use App\Enums\StepType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Rules\ScopedExists;
use Illuminate\Support\Facades\Validator;

/**
 * The role ceiling (layer two) and its interaction with tracker visibility (layer one).
 */
beforeEach(function () {
    $this->itTracker = makeTracker('IT Technical');
    $this->sdTracker = makeTracker('Systems Development');

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    $this->manager = makeUser('manager@gmail.com', UserRole::Manager);
    $this->member = makeUser('member@gmail.com', UserRole::Member);
    $this->viewer = makeUser('viewer@gmail.com', UserRole::Viewer);
    $this->outsider = makeUser('outsider@gmail.com', UserRole::Manager);   // Manager, but NOT a member

    foreach ([$this->manager, $this->member, $this->viewer] as $u) {
        addMember($this->itTracker, $u);
    }
    addMember($this->sdTracker, $this->outsider);

    [$this->project, $this->sdStep, $this->foreignProject] = SystemContext::run(function () {
        $step = Step::create([
            'tracker_id' => $this->itTracker->id, 'name' => 'Backlog',
            'type' => StepType::Intake, 'position' => 0,
        ]);
        $sdStep = Step::create([
            'tracker_id' => $this->sdTracker->id, 'name' => 'Backlog',
            'type' => StepType::Intake, 'position' => 0,
        ]);

        $project = Project::create([
            'tracker_id' => $this->itTracker->id, 'step_id' => $step->id,
            'name' => 'Firewall upgrade', 'owner_user_id' => $this->member->id,
            'last_activity_at' => now(), 'current_step_entered_at' => now(),
        ]);

        // A project the member has no business seeing at all — the boundary the
        // Trello-style loosening must NOT cross.
        $foreign = Project::create([
            'tracker_id' => $this->sdTracker->id, 'step_id' => $sdStep->id,
            'name' => 'Payroll phase 2', 'owner_user_id' => $this->outsider->id,
            'last_activity_at' => now(), 'current_step_entered_at' => now(),
        ]);

        return [$project, $sdStep, $foreign];
    });
});

describe('the role ceiling', function () {

    it('lets only admins create trackers', function () {
        expect(CapabilityMatrix::allows($this->admin, Capability::CreateTracker))->toBeTrue()
            ->and(CapabilityMatrix::allows($this->manager, Capability::CreateTracker))->toBeFalse()
            ->and(CapabilityMatrix::allows($this->member, Capability::CreateTracker))->toBeFalse();
    });

    it('denies a viewer every write capability but permits downloads', function () {
        expect(CapabilityMatrix::allows($this->viewer, Capability::CreateProject))->toBeFalse()
            ->and(CapabilityMatrix::allows($this->viewer, Capability::UploadAttachment))->toBeFalse()
            ->and(CapabilityMatrix::allows($this->viewer, Capability::Comment))->toBeFalse()
            // FR-4.5 would be meaningless if a member of a tracker could not open its files.
            ->and(CapabilityMatrix::allows($this->viewer, Capability::DownloadAttachment))->toBeTrue();
    });

    it('treats a suspended admin as having no capabilities at all', function () {
        $this->admin->update(['status' => UserStatus::Suspended]);

        expect(CapabilityMatrix::allows($this->admin->fresh(), Capability::ManageUsers))->toBeFalse();
    });
});

describe('both layers must pass', function () {

    it('lets a manager move any project inside their own tracker', function () {
        expect($this->manager->can('move', $this->project))->toBeTrue();
    });

    it('refuses a manager who is not a member, as NOT FOUND rather than forbidden', function () {
        // A 403 would confirm the project exists — the disclosure FR-2.9 forbids.
        $response = Gate::forUser($this->outsider)->inspect('view', $this->project);

        expect($response->allowed())->toBeFalse()
            ->and($response->status())->toBe(404);
    })->skip(fn () => ! class_exists(Illuminate\Support\Facades\Gate::class), 'Gate unavailable');

    // SETTLED, and a deliberate reversal: a Member used to be refused here unless they
    // owned or were assigned the card. That made the board unusable as a shared board —
    // the person who notices a ticket is done is rarely its owner — so the matrix now
    // grants Members the *any* variants. The control that replaced the lock is the
    // attributed activity row, asserted in ActivityLogTest.
    it('lets a member move any project inside their own tracker, owned or not', function () {
        expect($this->member->can('move', $this->project))->toBeTrue();

        SystemContext::run(fn () => $this->project->update(['owner_user_id' => null]));

        expect($this->member->fresh()->can('move', $this->project->fresh()))->toBeTrue()
            ->and($this->member->fresh()->can('update', $this->project->fresh()))->toBeTrue();
    });

    // The loosening stops at the tracker boundary. Membership is still layer one, and
    // it is still checked before the capability matrix is consulted.
    it('still refuses a member a project in a tracker they do not belong to', function () {
        $response = Gate::forUser($this->member)->inspect('move', $this->foreignProject);

        expect($response->allowed())->toBeFalse()
            ->and($response->status())->toBe(404);
    });

    // Archiving did NOT loosen: it removes work from every report, so it stays with
    // Admin and Manager.
    it('does not let a member archive a project', function () {
        expect($this->member->can('archive', $this->project))->toBeFalse();
    });

    it('never lets a viewer move a project, even one they somehow own', function () {
        SystemContext::run(fn () => $this->project->update(['owner_user_id' => $this->viewer->id]));

        expect($this->viewer->can('move', $this->project->fresh()))->toBeFalse();
    });
});

describe('ScopedExists closes the existence oracle', function () {

    // VERIFICATION.md authorization-5: bare `exists:` uses DB::table() internally and
    // bypasses the global scope, so a crafted POST can distinguish "this id exists
    // somewhere" from "it does not" — enumerable, and revealing the step structure of
    // trackers the attacker cannot see.
    it('rejects an id from an invisible tracker exactly as it rejects a missing one', function () {
        bindContextFor($this->member);

        $invisible = Validator::make(
            ['step_id' => $this->sdStep->id],
            ['step_id' => [new ScopedExists('steps')]],
        );

        $missing = Validator::make(
            ['step_id' => 999999],
            ['step_id' => [new ScopedExists('steps')]],
        );

        expect($invisible->fails())->toBeTrue()
            ->and($missing->fails())->toBeTrue()
            // Identical messages: no oracle survives.
            ->and($invisible->errors()->first('step_id'))
            ->toBe($missing->errors()->first('step_id'));
    });

    it('accepts an id from a tracker the user can see', function () {
        bindContextFor($this->member);

        $valid = Validator::make(
            ['step_id' => $this->project->step_id],
            ['step_id' => [new ScopedExists('steps')]],
        );

        expect($valid->fails())->toBeFalse();
    });
});
