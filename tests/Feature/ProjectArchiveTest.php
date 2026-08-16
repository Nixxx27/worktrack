<?php

use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\ProjectDrawer;
use App\Models\Step;
use App\Services\Metrics\MetricsRepository;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * FR-4.9 — "Projects are archived/soft-deleted, never hard-deleted, so historical
 * reporting stays intact."
 *
 * `ProjectPolicy::archive` was written when the policies were, and until now nothing in
 * the system called it: a capability in the matrix, enforced on a request no interface
 * could produce. These tests cover the seam that makes it real, and the property the
 * requirement actually cares about — that archiving hides work without erasing the
 * history every metric is derived from.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    $this->member = makeUser('joy@gmail.com');
    app(TrackerService::class)->addMember($this->tracker, $this->member, $this->admin);

    $this->project = app(ProjectService::class)->create(
        $this->tracker, ['name' => 'Replace ageing UPS units'], $this->admin
    );
});

describe('archiving', function () {

    it('takes the card off the board without deleting the row', function () {
        app(ProjectService::class)->archive($this->project, $this->admin);

        $fresh = $this->project->fresh();

        expect($fresh)->not->toBeNull()                       // the row is still there
            ->and($fresh->archived_at)->not->toBeNull()
            ->and($fresh->archived_by_user_id)->toBe($this->admin->id);

        Livewire::actingAs($this->admin)
            ->test(Board::class)
            ->assertDontSee('Replace ageing UPS units');
    });

    it('keeps the movement history the metrics are derived from', function () {
        // The requirement's whole reason for existing. Deleting the project would
        // silently rewrite finished history — last quarter's throughput would change
        // because someone tidied the board this morning.
        app(MovementRecorder::class)->recordMove($this->project->fresh(), $this->steps['In Progress'], $this->admin);
        $before = DB::table('project_step_movements')->where('project_id', $this->project->id)->count();

        app(ProjectService::class)->archive($this->project->fresh(), $this->admin);

        expect(DB::table('project_step_movements')->where('project_id', $this->project->id)->count())
            ->toBe($before);
    });

    it('leaves the open movement interval open rather than fabricating an exit', function () {
        // FR-4.8 makes movement history the one thing users never edit, and archiving is
        // not a step change. Nothing downstream needs the fiction: the step-occupancy
        // metric reads only closed intervals.
        app(ProjectService::class)->archive($this->project, $this->admin);

        $open = DB::table('project_step_movements')
            ->where('project_id', $this->project->id)
            ->whereNull('exited_at')
            ->count();

        expect($open)->toBe(1);
    });

    it('drops out of the dashboard the moment it is archived', function () {
        $metrics = app(MetricsRepository::class);
        expect($metrics->aging(50)->pluck('name'))->toContain('Replace ageing UPS units');

        app(ProjectService::class)->archive($this->project, $this->admin);

        expect($metrics->aging(50)->pluck('name'))->not->toContain('Replace ageing UPS units');
    });

    it('is never auto-flagged as stalled while archived', function () {
        // Otherwise every archived project reappears on the watchlist and in the stall
        // emails for ever, which is precisely the noise archiving was meant to remove.
        app(ProjectService::class)->archive($this->project, $this->admin);
        $this->project->fresh()->forceFill(['last_activity_at' => now()->subDays(90)])->save();

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($this->project->fresh()->health->value)->toBe('on_track');
    });

    it('records the removal without refreshing the stall clock', function () {
        $before = $this->project->last_activity_at;

        app(ProjectService::class)->archive($this->project, $this->admin);

        expect(DB::table('project_activities')->where('project_id', $this->project->id)
            ->where('type', 'archived')->exists())->toBeTrue()
            ->and($this->project->fresh()->last_activity_at->timestamp)->toBe($before->timestamp);
    });

    it('writes an admin audit entry, which create and move do not', function () {
        // FR-9.1 names project archival specifically. It is the only project operation
        // that removes work from every report at once.
        app(ProjectService::class)->archive($this->project, $this->admin);

        expect(DB::table('audit_logs')->where('action', 'project.archived')
            ->where('actor_user_id', $this->admin->id)->exists())->toBeTrue();
    });

    it('is idempotent, because the card may still be on a stale screen', function () {
        expect(app(ProjectService::class)->archive($this->project, $this->admin))->toBeTrue()
            ->and(app(ProjectService::class)->archive($this->project->fresh(), $this->admin))->toBeFalse();

        expect(DB::table('project_activities')->where('project_id', $this->project->id)
            ->where('type', 'archived')->count())->toBe(1);
    });
});

describe('restoring', function () {

    it('puts the card back on the board', function () {
        app(ProjectService::class)->archive($this->project, $this->admin);
        app(ProjectService::class)->restore($this->project->fresh(), $this->admin);

        expect($this->project->fresh()->archived_at)->toBeNull();

        Livewire::actingAs($this->admin)
            ->test(Board::class)
            ->assertSee('Replace ageing UPS units');
    });

    it('counts as activity, so the next sweep does not immediately flag it stalled', function () {
        // Stall detection skips archived projects, so a card restored after two months
        // carries a two-month-old last_activity_at. Without this the sweep flags it and
        // emails the person about their own click.
        $this->project->forceFill(['last_activity_at' => now()->subDays(90)])->save();

        app(ProjectService::class)->archive($this->project->fresh(), $this->admin);
        app(ProjectService::class)->restore($this->project->fresh(), $this->admin);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($this->project->fresh()->health->value)->toBe('on_track');
    });
});

describe('who may do it', function () {

    it('refuses a Member, who may edit and move but not archive', function () {
        // §5.2 keeps archiving with Admin/Manager precisely because it removes work from
        // every report — the one thing the "anyone can act, everyone can see who did"
        // trade was NOT extended to.
        Livewire::actingAs($this->member)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('archiveProject')
            ->assertForbidden();

        expect($this->project->fresh()->archived_at)->toBeNull();
    });

    it('hides the control from someone who cannot use it', function () {
        Livewire::actingAs($this->member)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertDontSee('Archive');
    });

    it('offers restore only to the same people', function () {
        app(ProjectService::class)->archive($this->project, $this->admin);

        Livewire::actingAs($this->member)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('restoreProject')
            ->assertForbidden();

        expect($this->project->fresh()->archived_at)->not->toBeNull();
    });

    it('cannot be reached for a project in a tracker you cannot see', function () {
        // 404, never 403 — a refusal that confirms the project exists is the disclosure
        // FR-2.9 forbids.
        $outsider = makeUser('outsider@gmail.com', UserRole::Manager);

        Livewire::actingAs($outsider)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertNotFound();
    });
});

describe('the drawer', function () {

    it('stays open on the archived card and offers the way back', function () {
        // The card has just vanished from the board behind it. Closing on top of that
        // would leave a mis-click with a project they can no longer see.
        Livewire::actingAs($this->admin)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->call('archiveProject')
            ->assertSet('open', true)
            ->assertSee('Archived')
            ->assertSee('Restore to the board');
    });

    it('still opens an archived project from a link', function () {
        // Notification and dashboard links outlive the board. A link to something
        // archived last week must land on the card, not on an error.
        app(ProjectService::class)->archive($this->project, $this->admin);

        Livewire::actingAs($this->admin)
            ->test(ProjectDrawer::class)
            ->call('openFor', $this->project->public_id)
            ->assertSet('open', true)
            ->assertSee('Replace ageing UPS units');
    });
});
