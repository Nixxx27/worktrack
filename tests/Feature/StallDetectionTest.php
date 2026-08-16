<?php

use App\Authorization\SystemContext;
use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Step;
use App\Services\Projects\ActivityRecorder;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Projects\StallThresholdResolver;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;

/**
 * FR-4.6 / US-4 — the promise that nothing quietly rots.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));
});

/** Backdate the activity clock to simulate elapsed idleness. */
function idleFor(Project $project, int $days): Project
{
    $project->forceFill(['last_activity_at' => now()->subDays($days)])->save();

    return $project->fresh();
}

describe('threshold resolution', function () {

    it('prefers the tracker override over the department and the global default', function () {
        // FR-10.2 names all three levels but not their order, and the order is
        // load-bearing: it decides which projects appear on the watchlist.
        $dept = DB::table('departments')->insertGetId([
            'name' => 'Networking', 'stall_threshold_days' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolver = app(StallThresholdResolver::class);

        expect($resolver->resolve($this->tracker->id, $dept, false))->toBe(3);   // dept wins over global

        DB::table('trackers')->where('id', $this->tracker->id)->update(['stall_threshold_days' => 14]);

        expect($resolver->resolve($this->tracker->id, $dept, false))->toBe(14);  // tracker wins over dept
    });

    it('falls back to the global default', function () {
        expect(app(StallThresholdResolver::class)->resolve($this->tracker->id, null, false))
            ->toBe(config('worktrack.stall.threshold_days'));
    });

    it('can give backlog a longer fuse than work in progress', function () {
        DB::table('trackers')->where('id', $this->tracker->id)->update([
            'stall_threshold_days' => 7,
            'stall_intake_threshold_days' => 21,
        ]);

        $resolver = app(StallThresholdResolver::class);

        expect($resolver->resolve($this->tracker->id, null, true))->toBe(21)
            ->and($resolver->resolve($this->tracker->id, null, false))->toBe(7);
    });
});

describe('the detector', function () {

    it('flags a project idle past its threshold', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Core switch'], $this->admin);
        idleFor($project, 9);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        $fresh = $project->fresh();

        expect($fresh->health)->toBe(ProjectHealth::Stalled)
            ->and($fresh->health_source)->toBe(HealthSource::Auto)
            ->and($fresh->stall_notified_at)->not->toBeNull();
    });

    it('leaves a fresh project alone', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Wi-Fi survey'], $this->admin);
        idleFor($project, 2);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($project->fresh()->health)->toBe(ProjectHealth::OnTrack);
    });

    it('never flags an On Hold project', function () {
        // FR-4.6 — a deliberate pause is not rot, and flagging it would make the
        // watchlist untrustworthy, which is worse than missing one entry.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'UPS replacement'], $this->admin);
        $project->forceFill([
            'health' => ProjectHealth::OnHold,
            'health_source' => HealthSource::Manual,
        ])->save();
        idleFor($project, 90);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($project->fresh()->health)->toBe(ProjectHealth::OnHold);
    });

    it('never flags finished work', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'SPF hardening'], $this->admin);
        app(MovementRecorder::class)->recordMove($project, $this->steps['Done'], $this->admin);
        idleFor($project->fresh(), 60);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($project->fresh()->health)->not->toBe(ProjectHealth::Stalled);
    });

    it('emails once per stall episode, not once per night', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall audit'], $this->admin);
        $member = makeUser('joy@gmail.com');
        app(TrackerService::class)->addMember($this->tracker, $member, $this->admin);
        $project->forceFill(['owner_user_id' => $member->id])->save();

        idleFor($project, 10);
        DB::table('notification_outbox')->delete();

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();
        $after = DB::table('notification_outbox')->where('event_type', 'project.stalled')->count();

        // Second night, still idle. Must not notify again.
        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($after)->toBeGreaterThan(0)
            ->and(DB::table('notification_outbox')->where('event_type', 'project.stalled')->count())->toBe($after);
    });

    it('notifies again after a project stalls a SECOND time', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'CCTV storage'], $this->admin);
        idleFor($project, 10);

        $this->artisan('worktrack:detect-stalled');

        // Activity clears the stall and resets the notification latch...
        app(ActivityRecorder::class)->record($project->fresh(), 'comment', $this->admin);
        expect($project->fresh()->health)->toBe(ProjectHealth::OnTrack)
            ->and($project->fresh()->stall_notified_at)->toBeNull();

        // ...so a fresh episode is a fresh notification.
        idleFor($project->fresh(), 10);
        DB::table('notification_outbox')->delete();
        $this->artisan('worktrack:detect-stalled');

        expect(DB::table('notification_outbox')->where('event_type', 'project.stalled')->count())
            ->toBeGreaterThan(0);
    });

    it('does not reset the stall clock with its own write', function () {
        // If the detector's own activity row counted, it would un-stall everything it
        // just flagged and the job would appear to do nothing at all.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Rack tidy'], $this->admin);
        $idleSince = idleFor($project, 12)->last_activity_at;

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($project->fresh()->last_activity_at->timestamp)->toBe($idleSince->timestamp)
            ->and($project->fresh()->health)->toBe(ProjectHealth::Stalled);
    });

    it('writes nothing on a dry run', function () {
        // The first real run flags every pre-existing project at once and emails about
        // all of them against the Gmail cap. --dry-run is how you size that first.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Switch firmware'], $this->admin);
        idleFor($project, 30);

        $this->artisan('worktrack:detect-stalled --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        expect($project->fresh()->health)->toBe(ProjectHealth::OnTrack)
            ->and(DB::table('notification_outbox')->count())->toBe(0);
    });

    it('ignores projects in an archived tracker', function () {
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Old thing'], $this->admin);
        idleFor($project, 40);
        app(TrackerService::class)->archive($this->tracker, $this->admin);

        $this->artisan('worktrack:detect-stalled')->assertSuccessful();

        expect($project->fresh()->health)->toBe(ProjectHealth::OnTrack);
    });
});
