<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Livewire\ActivityLog;
use App\Models\ProjectActivity;
use App\Models\Step;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * FR-9 — the review screen, and the attributed rows behind it.
 *
 * This file carries more weight than a normal UI test. The capability matrix now lets
 * any member move and edit any card on their trackers, and the justification for that
 * loosening is precisely that every action is recorded and reviewable. If these tests
 * fail, the permission model has no compensating control left.
 */
function logAs(User $user)
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
    $this->colleague = makeUser('rico@gmail.com', UserRole::Member);
    $this->outsider = makeUser('sam@gmail.com', UserRole::Member);

    $svc->addMember($this->itTracker, $this->member, $this->admin);
    $svc->addMember($this->itTracker, $this->colleague, $this->admin);
    $svc->addMember($this->sdTracker, $this->outsider, $this->admin);

    $this->project = app(ProjectService::class)->create(
        $this->itTracker,
        ['name' => 'Firewall upgrade'],
        $this->admin,
    );
});

/** @return Collection<int, object> */
function rowsFor(int $projectId)
{
    return DB::table('project_activities')->where('project_id', $projectId)->orderBy('id')->get();
}

describe('every action leaves an attributed row', function () {

    it('records creation with what the form collected', function () {
        $project = app(ProjectService::class)->create($this->itTracker, [
            'name' => 'Branch VPN failover',
            'start_date' => '2026-08-14',
            'target_date' => '2026-09-30',
            'owner_user_id' => $this->member->id,
            'assignees' => [$this->colleague->id],
            'tags' => ['network'],
        ], $this->member);

        $row = rowsFor($project->id)->firstWhere('type', 'created');
        $payload = json_decode($row->payload, true);

        expect($row->user_id)->toBe($this->member->id)
            // One row, not four: a project that did not exist a moment ago has not
            // been "edited" three times.
            ->and(rowsFor($project->id))->toHaveCount(1)
            ->and($payload['owner'])->toBe($this->member->name)
            ->and($payload['assignees'])->toBe([$this->colleague->name])
            ->and($payload['tags'])->toBe(['network'])
            ->and($payload['target_date'])->toBe('2026-09-30');
    });

    it('records a field edit with the before and after', function () {
        app(ProjectService::class)->update($this->project, [
            'name' => 'Firewall upgrade (phase 2)',
            'target_date' => '2026-12-31',
        ], $this->colleague);

        $row = rowsFor($this->project->id)->firstWhere('type', 'field_edit');
        $payload = json_decode($row->payload, true);

        expect($row->user_id)->toBe($this->colleague->id)
            // Keyed by the human label, so the log reads without a lookup table.
            ->and($payload['fields']['the title']['from'])->toBe('Firewall upgrade')
            ->and($payload['fields']['the title']['to'])->toBe('Firewall upgrade (phase 2)')
            ->and($payload['fields']['the due date']['to'])->toBe('2026-12-31');
    });

    it('records an owner change with both names', function () {
        app(ProjectService::class)->update($this->project, [
            'owner_user_id' => $this->member->id,
        ], $this->colleague);

        $row = rowsFor($this->project->id)->firstWhere('type', 'owner_changed');
        $payload = json_decode($row->payload, true);

        // The from side is captured BEFORE the save — read afterwards it would log
        // "changed the owner from Joy to Joy", which reads as a bug in the log.
        expect($payload['from'])->toBe($this->admin->name)
            ->and($payload['to'])->toBe($this->member->name)
            ->and($row->user_id)->toBe($this->colleague->id);
    });

    it('records assignment and unassignment separately', function () {
        $svc = app(ProjectService::class);

        $svc->update($this->project, ['assignees' => [$this->member->id]], $this->colleague);
        $svc->update($this->project, ['assignees' => [$this->colleague->id]], $this->member);

        $rows = rowsFor($this->project->id)->where('type', 'assignees_changed')->values();

        expect($rows)->toHaveCount(2)
            ->and(json_decode($rows[0]->payload, true)['added'])->toBe([$this->member->name])
            ->and(json_decode($rows[1]->payload, true)['added'])->toBe([$this->colleague->name])
            ->and(json_decode($rows[1]->payload, true)['removed'])->toBe([$this->member->name]);
    });

    it('records tagging', function () {
        app(ProjectService::class)->update($this->project, ['tags' => ['urgent']], $this->member);

        $row = rowsFor($this->project->id)->firstWhere('type', 'tags_changed');

        expect(json_decode($row->payload, true)['added'])->toBe(['urgent']);
    });

    it('records a move by someone who neither owns nor is assigned the card', function () {
        // The exact case the permission loosening created. If this row is missing,
        // the loosening has no compensating control.
        $step = SystemContext::run(fn () => Step::where('tracker_id', $this->itTracker->id)
            ->orderByDesc('position')->firstOrFail());

        app(ProjectService::class)->move($this->project, $step, $this->colleague);

        $row = rowsFor($this->project->id)->firstWhere('type', 'step_move');

        expect($row->user_id)->toBe($this->colleague->id)
            ->and(json_decode($row->payload, true)['to_step_name'])->toBe($step->name);
    });

    it('renders each row as one readable sentence', function () {
        $svc = app(ProjectService::class);

        $svc->update($this->project, ['owner_user_id' => $this->member->id], $this->colleague);
        $svc->update($this->project, ['tags' => ['urgent', 'network']], $this->colleague);
        $svc->update($this->project, ['name' => 'Renamed', 'priority' => 'high'], $this->colleague);

        $described = ProjectActivity::where('project_id', $this->project->id)
            ->get()
            ->map(fn (ProjectActivity $a) => $a->describe());

        expect($described)->toContain("changed the owner from {$this->admin->name} to {$this->member->name}")
            ->and($described)->toContain('tagged urgent and network')
            ->and($described)->toContain('edited the title and the priority');
    });
});

describe('the log screen respects tracker isolation', function () {

    beforeEach(function () {
        $this->foreign = SystemContext::run(fn () => app(ProjectService::class)
            ->create($this->sdTracker, ['name' => 'Payroll phase 2'], $this->admin));
    });

    it('shows a member only their own trackers\' activity', function () {
        logAs($this->member)->test(ActivityLog::class)
            ->assertSee('Firewall upgrade')
            ->assertDontSee('Payroll phase 2')
            ->assertDontSee('Systems Development');
    });

    it('shows an admin everything', function () {
        logAs($this->admin)->test(ActivityLog::class)
            ->assertSee('Firewall upgrade')
            ->assertSee('Payroll phase 2');
    });

    it('cannot be filtered into another tracker by pasting its id', function () {
        // Filters live in the URL, so a shared link carries a tracker public_id. It
        // must resolve through the scope, not around it.
        logAs($this->member)->test(ActivityLog::class)
            ->set('trackerId', $this->sdTracker->public_id)
            ->assertDontSee('Payroll phase 2');
    });

    it('cannot be searched into another tracker', function () {
        logAs($this->member)->test(ActivityLog::class)
            ->set('search', 'Payroll')
            ->assertDontSee('Payroll phase 2');
    });

    it('does not name people whose only presence is in an invisible tracker', function () {
        // The dropdown is derived from the scoped activity query, not from the users
        // table — otherwise the filter itself becomes the leak.
        $actors = logAs($this->member)->test(ActivityLog::class)->instance()->actors->pluck('name');

        expect($actors)->toContain($this->admin->name)
            ->and($actors)->not->toContain($this->outsider->name);
    });
});

describe('the admin audit log is a separate, admin-only surface', function () {

    beforeEach(function () {
        app(AuditLogger::class)->log('user.approved', $this->admin->id, ['email' => 'joy@gmail.com']);
    });

    it('is offered to an admin', function () {
        logAs($this->admin)->test(ActivityLog::class)
            ->set('view', 'system')
            ->assertSee('user.approved');
    });

    it('is not reachable by a member who sets the tab by hand', function () {
        // The tab is a URL-bound property, so "the button is hidden" is not the control.
        logAs($this->member)->test(ActivityLog::class)
            ->set('view', 'system')
            ->assertDontSee('user.approved');

        expect(logAs($this->member)->test(ActivityLog::class)->instance()->auditEntries)->toBeEmpty();
    });

    // DD-10: merging the two tables would put admin-only rows into one members read,
    // turning a table-level grant into a row-level filter.
    it('keeps admin events out of the work feed entirely', function () {
        logAs($this->admin)->test(ActivityLog::class)
            ->assertDontSee('user.approved');
    });
});

describe('the log is reachable over real HTTP', function () {

    it('renders for an approved member through the middleware stack', function () {
        resetContext();

        $this->actingAs($this->member)
            ->get('/activity')
            ->assertOk()
            ->assertSee('IT Technical')
            ->assertDontSee('Systems Development');
    });

    it('is refused to a pending account like every other authenticated route', function () {
        resetContext();

        $pending = makeUser('waiting@gmail.com', UserRole::Member, UserStatus::Pending);

        $this->actingAs($pending)->get('/activity')->assertRedirect(route('auth.pending'));
    });
});
