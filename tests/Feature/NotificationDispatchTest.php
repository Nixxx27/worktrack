<?php

use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\WorktrackNotification;
use App\Models\Step;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * FR-7 — the outbox actually draining.
 *
 * OutboxWriter had full coverage of what it WRITES, and nothing at all read those rows:
 * every step change, mention, assignment and stall notice in the system accumulated in
 * `notification_outbox` and no user ever received an email. These tests are about the
 * other half — who is still entitled to a message by the time it goes out, what happens
 * when SMTP refuses it, and what happens to a row whose sender died holding it.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    // The owner, and the person every assertion below is about. Deliberately not the
    // admin: the actor is excluded from their own notifications, and admins default to
    // digest — both would mask a dispatcher that sends nothing.
    $this->member = makeUser('joy@gmail.com');
    app(TrackerService::class)->addMember($this->tracker, $this->member, $this->admin);
});

/** A project owned by the member, moved by the admin — one pending row for the member. */
function movedProject(string $name = 'Core switch replacement', ?string $to = 'In Progress'): object
{
    $project = app(ProjectService::class)->create(test()->tracker, ['name' => $name], test()->admin);
    $project->forceFill(['owner_user_id' => test()->member->id])->save();

    app(MovementRecorder::class)->recordMove($project->fresh(), test()->steps[$to], test()->admin);

    return $project->fresh();
}

/** Past the coalesce window, so an `immediate` row is due. */
function pastCoalesceWindow(): void
{
    test()->travel(config('worktrack.notifications.coalesce_minutes') + 1)->minutes();
}

function outboxFor(int $userId): object
{
    return DB::table('notification_outbox')->where('user_id', $userId)->orderBy('id')->first();
}

describe('sending', function () {

    it('sends a due step change to the recipient and marks the row sent', function () {
        Mail::fake();
        movedProject();

        expect(outboxFor($this->member->id)->status)->toBe('pending');

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertSent(WorktrackNotification::class, fn ($mail) => $mail->hasTo($this->member->email));

        $row = outboxFor($this->member->id);
        expect($row->status)->toBe('sent')
            ->and($row->sent_at)->not->toBeNull()
            ->and($row->attempts)->toBe(1);
    });

    it('does not send before the coalesce window closes', function () {
        // FR-7.8 — the window is what collapses three drags into one email. Sending on
        // the first tick would mean the window exists in the schema and nowhere else.
        Mail::fake();
        movedProject();

        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->status)->toBe('pending');
    });

    it('batches everything one recipient is owed into a single email', function () {
        Mail::fake();
        movedProject('Core switch replacement');
        movedProject('Branch VPN failover');
        movedProject('UPS replacement');

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        // Three events, three outbox rows, ONE message. Per-event sending would put
        // three near-identical emails in one inbox inside a second, against a Gmail cap
        // that ARCHITECTURE.md §8 names as this design's central risk.
        Mail::assertSentCount(1);
        Mail::assertSent(WorktrackNotification::class, fn ($mail) => count($mail->events) === 3);

        expect(DB::table('notification_outbox')->where('user_id', $this->member->id)
            ->where('status', 'sent')->count())->toBe(3);
    });

    it('holds a digest row until its hour, then sends it', function () {
        // FR-7.7 / the admin default. Digest is ON for admins because they receive every
        // step change in every tracker and are the only people guaranteed to drown.
        Mail::fake();
        $other = makeUser('nz@example.com');
        app(TrackerService::class)->addMember($this->tracker, $other, $this->admin);

        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Firewall audit'], $this->member);
        app(MovementRecorder::class)->recordMove($project->fresh(), $this->steps['In Progress'], $other);

        $adminRow = outboxFor($this->admin->id);
        expect($adminRow->delivery_mode)->toBe('digest');

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications');
        Mail::assertNotSent(WorktrackNotification::class, fn ($mail) => $mail->hasTo($this->admin->email));

        $this->travelTo($adminRow->available_at);
        $this->artisan('worktrack:dispatch-notifications');

        Mail::assertSent(WorktrackNotification::class, fn ($mail) => $mail->hasTo($this->admin->email));
        expect(outboxFor($this->admin->id)->status)->toBe('sent');
    });

    it('claims nothing on a dry run', function () {
        Mail::fake();
        movedProject();
        pastCoalesceWindow();

        $this->artisan('worktrack:dispatch-notifications --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->status)->toBe('pending');
    });
});

describe('authorization is re-asserted at send time', function () {

    // VERIFICATION.md data-model-2. Recipients are frozen when the event happens, but a
    // digest row can be delivered 24 hours later. Snapshotting CONTENT is correct;
    // snapshotting AUTHORIZATION is the bug — in that window someone can be removed from
    // the tracker, suspended, or mute it, and the email would still reveal a tracker they
    // can no longer see (FR-2.9 / NFR-S3).

    it('suppresses a row when the recipient has left the tracker', function () {
        Mail::fake();
        movedProject();

        app(TrackerService::class)->removeMember($this->tracker, $this->member, $this->admin);

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertNothingSent();

        $row = outboxFor($this->member->id);
        expect($row->status)->toBe('suppressed')
            ->and($row->suppressed_reason)->toBe('not_member');
    });

    it('suppresses a row when the recipient has been suspended', function () {
        // FR-1.7 — suspension takes effect on the next request, and mail is a request
        // made on their behalf.
        Mail::fake();
        movedProject();

        $this->member->forceFill(['status' => UserStatus::Suspended])->save();

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->suppressed_reason)->toBe('not_active');
    });

    it('suppresses a row for a tracker muted after the event', function () {
        Mail::fake();
        movedProject();

        DB::table('user_tracker_mutes')->insert([
            'user_id' => $this->member->id,
            'tracker_id' => $this->tracker->id,
            'muted_at' => now(),
        ]);

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->suppressed_reason)->toBe('muted');
    });

    it('suppresses an event type the recipient has turned off', function () {
        Mail::fake();
        movedProject();

        DB::table('notification_preferences')->insert([
            'user_id' => $this->member->id,
            'delivery_mode' => 'immediate',
            'digest_hour' => 8,
            'muted_events' => json_encode(['project.moved']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->suppressed_reason)->toBe('muted');
    });

    it('still delivers to an admin who is not a member of the tracker', function () {
        // D9/FR-7.3 — the deliberate exception. A membership requirement applied evenly
        // would make every admin notification unsendable, which is why the composite FK
        // the verification report suggested was rejected in the migration.
        Mail::fake();

        // A second admin, because the one in beforeEach created the tracker and
        // TrackerService adds its creator as a member.
        $outsider = makeUser('boss@example.com', UserRole::Admin);

        // Asserted on the membership ROW, not isMemberOf(), which answers true for any
        // admin by design (D9) — and the row is what the dispatcher's re-assertion reads.
        expect(DB::table('tracker_members')->where('user_id', $outsider->id)->exists())->toBeFalse();

        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'CCTV storage'], $this->member);
        app(MovementRecorder::class)->recordMove($project->fresh(), $this->steps['In Progress'], $this->member);

        $this->travelTo(outboxFor($outsider->id)->available_at);
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        Mail::assertSent(WorktrackNotification::class, fn ($mail) => $mail->hasTo($outsider->email));
    });
});

describe('failure handling', function () {

    /** Make the configured mailer throw on resolve, the way an unreachable SMTP host does. */
    function explodingMailer(): void
    {
        config(['mail.default' => 'exploding', 'mail.mailers.exploding' => ['transport' => 'exploding']]);
        Mail::extend('exploding', fn () => throw new RuntimeException('Connection could not be established'));
    }

    it('requeues with backoff and records the error', function () {
        // FR-7.6 — failed sends retry with backoff and are logged. A row that failed
        // silently and stayed pending would retry every minute for ever.
        movedProject();
        explodingMailer();

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')
            ->expectsOutputToContain('will retry')
            ->assertSuccessful();

        $row = outboxFor($this->member->id);
        expect($row->status)->toBe('pending')
            ->and($row->attempts)->toBe(1)
            ->and($row->last_error)->toContain('Connection could not be established')
            ->and($row->available_at)->toBeGreaterThan(now()->toDateTimeString());
    });

    it('fails permanently once retries are exhausted, and stays visible', function () {
        // FR-7.6 — "a permanently failed notification must be visible to admins rather
        // than silently dropped". The row IS that visibility.
        config(['worktrack.notifications.max_attempts' => 1]);
        movedProject();
        explodingMailer();

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')
            ->expectsOutputToContain('permanently failed')
            ->assertSuccessful();

        expect(outboxFor($this->member->id)->status)->toBe('failed');

        $this->artisan('worktrack:dispatch-notifications --health')
            ->expectsOutputToContain('failed')
            ->assertSuccessful();
    });

    it('reclaims a row abandoned in sending by a process that died', function () {
        // While a row sits in 'sending' its generated coalesce_key is NULL, so it is
        // invisible to the poller AND to coalescing: without a reaper nothing would ever
        // look at it again, which is the one failure this table exists to prevent.
        Mail::fake();
        movedProject();

        DB::table('notification_outbox')->where('user_id', $this->member->id)->update([
            'status' => 'sending',
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('worktrack:dispatch-notifications')
            ->expectsOutputToContain('reclaimed')
            ->assertSuccessful();

        Mail::assertSent(WorktrackNotification::class);
        expect(outboxFor($this->member->id)->status)->toBe('sent');
    });

    it('merges a reclaimed row into the newer pending row that took its coalesce key', function () {
        // The nastiest edge in the design. Returning a claimed row to 'pending'
        // recomputes coalesce_key, and a NEW event for the same (user, type, project)
        // that arrived while we held it already owns that key — so the UPDATE violates
        // uk_outbox_coalesce. Failing the old row would lose its content even though a
        // perfectly good pending row exists for the same card, so it is merged instead.
        Mail::fake();
        $project = movedProject('Replace ageing UPS units', 'New');

        // Row A: the first move, held by a sender that then died.
        DB::table('notification_outbox')->where('user_id', $this->member->id)->update([
            'status' => 'sending',
            'updated_at' => now()->subHour(),
        ]);
        $rowA = outboxFor($this->member->id);

        // Row B: a second move, which inserts fresh because A's key is NULL.
        app(MovementRecorder::class)->recordMove($project->fresh(), $this->steps['In Progress'], $this->admin);

        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        $a = DB::table('notification_outbox')->where('id', $rowA->id)->first();
        $b = DB::table('notification_outbox')->where('user_id', $this->member->id)
            ->where('id', '!=', $rowA->id)->first();

        expect($a->status)->toBe('suppressed')
            ->and($a->suppressed_reason)->toBe('superseded');

        // The surviving row describes the NET move: where the card started, per the
        // reclaimed row, and where it ended up, per the newer one.
        $payload = json_decode($b->payload, true);
        expect($payload['from_step'])->toBe('Backlog')
            ->and($payload['to_step'])->toBe('In Progress');
    });

    it('stops at the daily send cap and leaves the rest pending', function () {
        // The Gmail cap is an external hard limit. Stopping early leaves rows pending and
        // countable; stopping late means Google refusing mail we have marked sent.
        Mail::fake();
        movedProject();
        config(['worktrack.notifications.daily_send_cap' => 0]);

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')
            ->expectsOutputToContain('cap reached')
            ->assertSuccessful();

        Mail::assertNothingSent();
        expect(outboxFor($this->member->id)->status)->toBe('pending');
    });
});

describe('what the email actually says', function () {

    it('renders the FR-7.2 content: tracker, project, both steps, actor, dwell time and a link', function () {
        // Rendered for real through the array transport rather than asserted against a
        // faked mailable, because the whole point of this test is that the blade views
        // compile and that the required facts survive into the body someone reads.
        $project = app(ProjectService::class)->create($this->tracker, ['name' => 'Core switch replacement'], $this->admin);
        $project->forceFill(['owner_user_id' => $this->member->id])->save();

        // Sent and cleared, so the move below opens a fresh row rather than coalescing
        // into this one — the dwell time only exists once a step has been left.
        app(MovementRecorder::class)->recordMove($project->fresh(), $this->steps['New'], $this->admin);
        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        $this->travel(3)->days();
        app(MovementRecorder::class)->recordMove($project->fresh(), $this->steps['In Progress'], $this->admin);

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(2);

        $email = $messages->last()->getOriginalMessage();
        $body = $email->getHtmlBody();

        expect($email->getSubject())->toContain('Core switch replacement')
            ->and($body)->toContain('IT Technical')          // tracker name
            ->and($body)->toContain('Core switch replacement')
            ->and($body)->toContain('New')                   // from step
            ->and($body)->toContain('In Progress')           // to step
            ->and($body)->toContain($this->admin->name)      // who moved it
            ->and($body)->toContain('3 days')                // how long it sat there
            ->and($body)->toContain($project->public_id);    // FR-7.2 direct link to the card
    });

    it('titles a batch as a digest rather than as one of its events', function () {
        movedProject('Core switch replacement');
        movedProject('Branch VPN failover');

        pastCoalesceWindow();
        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        expect($email->getSubject())->toBe('Worktrack — 2 updates')
            ->and($email->getHtmlBody())->toContain('Core switch replacement')
            ->and($email->getHtmlBody())->toContain('Branch VPN failover');
    });

    it('falls back to a generic block for an event type with no partial', function () {
        // FR-7.4 names events that have no writer yet. Whichever ships first must produce
        // a usable email on the day it is written rather than an exception inside the
        // mailer that burns the row's retries on a missing template.
        expect(WorktrackNotification::viewFor('tracker.member_added'))->toBe('mail.events.generic');

        DB::table('notification_outbox')->insert([
            'user_id' => $this->member->id,
            'tracker_id' => $this->tracker->id,
            'project_id' => null,
            'event_type' => 'tracker.member_added',
            'payload' => json_encode(['tracker_name' => 'IT Technical']),
            'delivery_mode' => 'immediate',
            'status' => 'pending',
            'available_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('worktrack:dispatch-notifications')->assertSuccessful();

        expect(outboxFor($this->member->id)->status)->toBe('sent')
            ->and(Mail::mailer()->getSymfonyTransport()->messages()->first()
                ->getOriginalMessage()->getHtmlBody())->toContain('Tracker member added');
    });
});
