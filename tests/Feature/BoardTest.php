<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\Board;
use App\Models\Project;
use App\Models\Step;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Board component behaviour.
 *
 * IMPORTANT HARNESS NOTE. Livewire::test() does NOT run the web middleware group, so
 * EstablishAccessContext never fires and the AccessContext stays bound to whoever was
 * bound last. The first version of this file was therefore testing nothing: a Member
 * rendered the admin's full tracker list and the assertion only failed by luck.
 *
 * Two consequences, both applied below:
 *   1. `asUser()` binds BOTH the auth user and the access context, so component tests
 *      exercise the same visibility production would compute.
 *   2. The isolation claim is ALSO asserted over real HTTP at the bottom of this file,
 *      because that is the only path that proves the middleware itself works. A
 *      component test can never prove that.
 */
function asUser(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

/**
 * The order the board renders a column in, which is board_position ascending. Read
 * through the system context on purpose: a test asserting where a card LANDED should
 * not also be able to fail because the card became invisible. Visibility is asserted
 * on its own, above.
 */
function boardOrder(int $stepId): array
{
    return SystemContext::run(fn () => Project::where('step_id', $stepId)
        ->orderBy('board_position')->pluck('name')->all());
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->itTracker = $svc->create(['name' => 'IT Technical'], $this->admin);
    $this->sdTracker = $svc->create(['name' => 'Systems Development'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $this->viewer = makeUser('ella@gmail.com', UserRole::Viewer);
    $svc->addMember($this->itTracker, $this->member, $this->admin);
    $svc->addMember($this->itTracker, $this->viewer, $this->admin);

    $this->itProject = app(ProjectService::class)->create($this->itTracker, ['name' => 'Firewall upgrade'], $this->admin);
    $this->sdProject = app(ProjectService::class)->create($this->sdTracker, ['name' => 'Payroll phase 2'], $this->admin);

    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->itTracker->id)
        ->orderBy('position')->get()->keyBy('name'));
});

describe('the board only shows what you may see', function () {

    it('shows a member their own tracker and nothing else', function () {
        asUser($this->member)->test(Board::class)
            ->assertSee('IT Technical')
            ->assertSee('Firewall upgrade')
            // The most direct assertion of FR-2.9 available in the UI: the tracker
            // switcher is the exact query that leaked when the scope was column-fixed.
            ->assertDontSee('Systems Development')
            ->assertDontSee('Payroll phase 2');
    });

    it('shows an admin every tracker', function () {
        asUser($this->admin)->test(Board::class)
            ->assertSee('IT Technical')
            ->assertSee('Systems Development');
    });

    it('explains itself to an approved user with no trackers', function () {
        // FR-1.10 — a blank screen reads as broken; this must read as an explanation.
        $orphan = makeUser('newhire@gmail.com', UserRole::Viewer);

        asUser($orphan)->test(Board::class)
            ->assertSee('No trackers yet')
            // Apostrophe deliberately avoided: assertSee escapes the needle but the
            // rendered copy contains a literal ', so the two never match.
            ->assertSee('added you to a tracker yet')
            // A member must NOT be offered the admin escape hatch.
            ->assertDontSee('Create a tracker');
    });

    it('offers an admin a way out of the empty state instead of the same dead end', function () {
        SystemContext::run(fn () => DB::table('trackers')->update(['archived_at' => now()]));

        asUser($this->admin)->test(Board::class)
            ->assertSee('No trackers yet')
            ->assertSee('Create a tracker');
    });
});

describe('creating a project', function () {

    // The board no longer creates anything itself — it opens the dialog, which is a
    // separate component tested in ProjectCreationTest. What the board still owns is
    // the decision to open it at all, and the service path underneath.

    it('opens the dialog for someone who may create', function () {
        asUser($this->member)->test(Board::class)
            ->call('newProject')
            ->assertHasNoErrors()
            ->assertDispatched('project-modal:create', tracker: $this->itTracker->public_id);
    });

    it('refuses a viewer before the dialog ever opens', function () {
        asUser($this->viewer)->test(Board::class)
            ->call('newProject')
            ->assertForbidden()
            ->assertNotDispatched('project-modal:create');
    });

    it('lands a new project in the first intake step with an open movement', function () {
        // FR-4.1's one-field path survives the new form: the service still requires
        // nothing but a name, so seeders and imports are unaffected.
        $project = app(ProjectService::class)->create($this->itTracker, ['name' => 'UPS replacement'], $this->member);

        expect($project->step_id)->toBe($this->steps['Backlog']->id)
            ->and(DB::table('project_step_movements')
                ->where('project_id', $project->id)->whereNull('exited_at')->exists())->toBeTrue();
    });

    it('re-reads the board when the dialog reports a save', function () {
        // Without this the card exists in the database and not on the screen, which
        // reads to the user as the Create button having done nothing.
        $component = asUser($this->member)->test(Board::class);

        app(ProjectService::class)->create($this->itTracker, ['name' => 'Branch VPN failover'], $this->member);

        $component->dispatch('project-saved', project: 'irrelevant')
            ->assertSee('Branch VPN failover');
    });
});

describe('what a card says without being opened', function () {

    it('shows the priority on every card, not only the loud ones', function () {
        // A chip that only renders for urgent/high makes its absence ambiguous: a
        // normal card and an untriaged one would look identical. The whole point of
        // putting it on the card front is deciding what to pick up without opening
        // anything, so all four values render.
        SystemContext::run(fn () => $this->itProject->update(['priority' => 'normal']));

        $urgent = app(ProjectService::class)->create(
            $this->itTracker, ['name' => 'Core switch down', 'priority' => 'urgent'], $this->admin);

        asUser($this->member)->test(Board::class)
            ->assertSeeHtml('title="Priority: normal"')
            ->assertSeeHtml('title="Priority: urgent"');

        expect($urgent->priority)->toBe('urgent');
    });

    it('colours urgent and high so they are found by glance, and leaves the rest neutral', function () {
        // The tones are the health tokens on purpose — on a board, "urgent" and
        // "behind" want the same glance. Neutral for normal/low is what keeps that
        // glance meaningful.
        //
        // The card's health stays on_track throughout, so a stalled or at-risk tone
        // can only have come from the priority chip. That is what makes asserting the
        // class fragment meaningful here rather than tautological.
        $tones = [
            'urgent' => 'bg-health-stalled-bg text-health-stalled',
            'high' => 'bg-health-atrisk-bg text-health-atrisk',
            'low' => 'bg-canvas-sunken text-ink-faint',
            'normal' => 'bg-canvas-sunken text-ink-soft',
        ];

        foreach ($tones as $priority => $tone) {
            SystemContext::run(fn () => $this->itProject->update(['priority' => $priority]));

            asUser($this->member)->test(Board::class)
                ->assertSeeHtml('tracking-wider '.$tone)
                ->assertSeeHtml('title="Priority: '.$priority.'"');
        }
    });
});

describe('moving a card', function () {

    it('records the move when an admin drops it', function () {
        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $this->steps['In Progress']->id)
            ->assertHasNoErrors();

        expect($this->itProject->fresh()->step_id)->toBe($this->steps['In Progress']->id)
            ->and(DB::table('project_step_movements')->where('project_id', $this->itProject->id)->count())->toBe(2);
    });

    it('refuses a viewer, and the card does not move', function () {
        // The optimistic UI has already moved it client-side; re-rendering from the
        // database is what snaps it back.
        $before = $this->itProject->step_id;

        asUser($this->viewer)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $this->steps['In Progress']->id)
            ->assertForbidden();

        expect($this->itProject->fresh()->step_id)->toBe($before);
    });

    it('lets a member move any card on their own tracker, owned or not', function () {
        // Trello-style, settled: a Member may move anything on a tracker they belong
        // to, including a card an admin owns. The record — not the lock — is the
        // control, so the move must also be attributed. See ActivityLogTest.
        $mine = app(ProjectService::class)->create($this->itTracker, ['name' => 'Mine'], $this->member);

        asUser($this->member)->test(Board::class)
            ->call('moveProject', $mine->public_id, $this->steps['In Progress']->id)
            ->assertHasNoErrors();

        asUser($this->member)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $this->steps['In Progress']->id)
            ->assertHasNoErrors();

        expect(DB::table('project_activities')
            ->where('project_id', $this->itProject->id)
            ->where('type', 'step_move')
            ->where('user_id', $this->member->id)
            ->exists())->toBeTrue();
    });

    it('cannot resolve a project in an invisible tracker at all', function () {
        // Route-model resolution goes through the scope, so the record never binds and
        // the failure is a not-found rather than a forbidden. That distinction is the
        // whole point: a 403 would confirm the project exists.
        expect(fn () => asUser($this->member)->test(Board::class)
            ->call('moveProject', $this->sdProject->public_id, $this->steps['In Progress']->id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('cannot move a card into another tracker\'s step', function () {
        $foreignStep = SystemContext::run(fn () => Step::where('tracker_id', $this->sdTracker->id)->firstOrFail());

        expect(fn () => asUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $foreignStep->id))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('where a dropped card lands', function () {

    beforeEach(function () {
        // 'Firewall upgrade' already sits in Backlog from the outer setup, so these
        // two land below it: Firewall, Second, Third.
        $this->backlog = $this->steps['Backlog'];

        foreach (['Second', 'Third'] as $name) {
            app(ProjectService::class)->create($this->itTracker, ['name' => $name], $this->admin);
        }
    });

    it('puts a card dropped on the top of its own column at the top', function () {
        // The regression. The client sends no after-card when the card lands at index
        // 0, and reading that as "append" sent every top drop to the bottom instead —
        // the one drop position a user cannot express any other way.
        $third = SystemContext::run(fn () => Project::where('name', 'Third')->firstOrFail());

        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $third->public_id, $this->backlog->id, null)
            ->assertHasNoErrors();

        expect(boardOrder($this->backlog->id))->toBe(['Third', 'Firewall upgrade', 'Second']);
    });

    it('puts a card dropped on the top of a different column at the top', function () {
        $inProgress = $this->steps['In Progress'];

        // Something to land above, otherwise the empty-column path is what runs.
        $resident = app(ProjectService::class)->create($this->itTracker, ['name' => 'Resident'], $this->admin);
        app(ProjectService::class)->move($resident, $inProgress, $this->admin);

        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $inProgress->id, null)
            ->assertHasNoErrors();

        expect(boardOrder($inProgress->id))->toBe(['Firewall upgrade', 'Resident']);
    });

    it('still drops a card between the two it was dropped between', function () {
        $third = SystemContext::run(fn () => Project::where('name', 'Third')->firstOrFail());

        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $third->public_id, $this->backlog->id, $this->itProject->id)
            ->assertHasNoErrors();

        expect(boardOrder($this->backlog->id))->toBe(['Firewall upgrade', 'Third', 'Second']);
    });

    it('appends into an empty column, where there is no top to sit above', function () {
        $inProgress = $this->steps['In Progress'];

        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $this->itProject->public_id, $inProgress->id, null)
            ->assertHasNoErrors();

        expect(boardOrder($inProgress->id))->toBe(['Firewall upgrade']);
    });

    it('falls back to the bottom when the card it was dropped below has gone', function () {
        // Someone else archived or moved it mid-drag. Unlike a top drop this genuinely
        // has no target, so the bottom is the honest answer rather than a guess.
        $third = SystemContext::run(fn () => Project::where('name', 'Third')->firstOrFail());

        asUser($this->admin)->test(Board::class)
            ->call('moveProject', $third->public_id, $this->backlog->id, 99999)
            ->assertHasNoErrors();

        expect(boardOrder($this->backlog->id))->toBe(['Firewall upgrade', 'Second', 'Third']);
    });

    it('survives repeated drops on the top without two cards colliding', function () {
        // The reason the top branch steps down by a fixed gap instead of halving.
        // Halving converges on zero and DECIMAL(20,10) runs out after roughly 43 of
        // these, at which point two cards share a position and the order goes random.
        $names = [];

        for ($i = 1; $i <= 60; $i++) {
            $name = 'Top '.$i;
            $names[] = $name;

            $card = app(ProjectService::class)->create($this->itTracker, ['name' => $name], $this->admin);

            asUser($this->admin)->test(Board::class)
                ->call('moveProject', $card->public_id, $this->backlog->id, null)
                ->assertHasNoErrors();
        }

        $positions = SystemContext::run(fn () => Project::where('step_id', $this->backlog->id)
            ->pluck('board_position')->all());

        expect(array_slice(boardOrder($this->backlog->id), 0, 60))->toBe(array_reverse($names))
            ->and(count(array_unique($positions)))->toBe(count($positions));
    });
});

describe('isolation over real HTTP, through the middleware stack', function () {

    // These exist because a Livewire component test CANNOT prove the middleware works
    // — it never runs it. Livewire funnels every interaction through one shared
    // endpoint, so if EstablishAccessContext were ever moved off the global web group,
    // every component test above would still pass while production leaked.
    it('binds the access context from the session on a real request', function () {
        $this->actingAs($this->member)
            ->get('/board')
            ->assertOk()
            ->assertSee('IT Technical')
            ->assertDontSee('Systems Development');
    });

    it('gives an admin the full list on a real request', function () {
        $this->actingAs($this->admin)
            ->get('/board')
            ->assertOk()
            ->assertSee('IT Technical')
            ->assertSee('Systems Development');
    });

    it('never reaches the board for a pending account', function () {
        $pending = makeUser('waiting@gmail.com', UserRole::Member, UserStatus::Pending);

        $this->actingAs($pending)->get('/board')->assertRedirect(route('auth.pending'));
    });
});
