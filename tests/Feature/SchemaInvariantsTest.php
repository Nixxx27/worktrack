<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Schema-level invariants.
 *
 * These assert guarantees enforced by MySQL itself, not by application code. They
 * exist because Worktrack's tracker-isolation boundary (NFR-S3 / FR-2.9) is a
 * database guarantee: if any of these fail, no amount of correct application code
 * makes the system safe.
 *
 * They MUST run against MySQL. See phpunit.xml — sqlite does not reproduce
 * composite foreign keys, stored generated columns, or CHECK constraints, and would
 * report green while proving nothing.
 */

/** Assert a statement is rejected by the database, and return the MySQL errno. */
function rejectedWithErrno(callable $fn): int
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    throw new Exception('Statement was NOT rejected by the database — invariant is not enforced.');
}

beforeEach(function () {
    $now = now();

    DB::table('users')->insert([
        ['id' => 10, 'name' => 'Bob', 'email' => 'bob@gmail.com', 'auth_provider' => 'google',
            'role' => 'member', 'status' => 'active', 'timezone' => 'Asia/Manila',
            'created_at' => $now, 'updated_at' => $now],
        ['id' => 11, 'name' => 'Amy', 'email' => 'amy@gmail.com', 'auth_provider' => 'google',
            'role' => 'member', 'status' => 'active', 'timezone' => 'Asia/Manila',
            'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('trackers')->insert([
        ['id' => 1, 'public_id' => str_repeat('A', 26), 'name' => 'IT Technical', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'public_id' => str_repeat('B', 26), 'name' => 'Systems Dev', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('tracker_members')->insert([
        ['tracker_id' => 1, 'user_id' => 10, 'added_at' => $now],
        ['tracker_id' => 1, 'user_id' => 11, 'added_at' => $now],
    ]);

    DB::table('steps')->insert([
        ['id' => 100, 'tracker_id' => 1, 'name' => 'Backlog', 'type' => 'intake', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 101, 'tracker_id' => 1, 'name' => 'In Progress', 'type' => 'active', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 200, 'tracker_id' => 2, 'name' => 'Backlog', 'type' => 'intake', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('projects')->insert([
        'id' => 1, 'public_id' => str_repeat('P', 26), 'tracker_id' => 1, 'step_id' => 100,
        'name' => 'Firewall upgrade', 'last_activity_at' => $now, 'current_step_entered_at' => $now,
        'tasks_total' => 2, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('tasks')->insert([
        ['id' => 1, 'tracker_id' => 1, 'project_id' => 1, 'title' => 'assigned to bob', 'assignee_user_id' => 10, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'tracker_id' => 1, 'project_id' => 1, 'title' => 'assigned to amy', 'assignee_user_id' => 11, 'created_at' => $now, 'updated_at' => $now],
    ]);
});

describe('tracker isolation is enforced by the engine', function () {

    it('refuses a task that points at a project in a different tracker', function () {
        $errno = rejectedWithErrno(fn () => DB::table('tasks')->insert([
            'tracker_id' => 2, 'project_id' => 1, 'title' => 'smuggled',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($errno)->toBe(1452);   // FK violation
    });

    it('refuses to move a project into another tracker\'s step', function () {
        $errno = rejectedWithErrno(fn () => DB::table('projects')->where('id', 1)->update(['step_id' => 200]));

        expect($errno)->toBe(1452);
    });

    it('refuses to assign a project to someone who is not a member of its tracker', function () {
        // FR-4.3 — "you cannot assign work to someone who can't see it" is a database
        // invariant, so a crafted POST cannot defeat it.
        $errno = rejectedWithErrno(fn () => DB::table('project_assignees')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'user_id' => 999, 'assigned_at' => now(),
        ]));

        expect($errno)->toBe(1452);
    });
});

describe('movement history invariants', function () {

    it('allows only one open movement row per project', function () {
        DB::table('project_step_movements')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'seq' => 1, 'to_step_id' => 100,
            'to_step_type' => 'intake', 'reason' => 'created',
            'entered_at' => '2026-08-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $errno = rejectedWithErrno(fn () => DB::table('project_step_movements')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'seq' => 2, 'to_step_id' => 101,
            'to_step_type' => 'active', 'entered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($errno)->toBe(1062);   // "a project is in exactly one step" is a DB invariant
    });

    it('computes duration_seconds as a generated column that cannot drift', function () {
        DB::table('project_step_movements')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'seq' => 1, 'to_step_id' => 100,
            'to_step_type' => 'intake', 'reason' => 'created',
            'entered_at' => '2026-08-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('project_step_movements')->where('project_id', 1)->where('seq', 1)
            ->update(['exited_at' => '2026-08-05 12:00:00']);

        // 4.5 days. Never written by application code, so it cannot disagree with its
        // own timestamps even if a request dies mid-write.
        expect(DB::table('project_step_movements')->where('seq', 1)->value('duration_seconds'))
            ->toBe(388800);
    });

    it('keeps step type as a frozen snapshot so retyping a step cannot rewrite history', function () {
        DB::table('project_step_movements')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'seq' => 1, 'to_step_id' => 101,
            'to_step_type' => 'active', 'reason' => 'created',
            'entered_at' => '2026-08-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Admin retypes "In Progress" from active to terminal (FR-3.1 permits this).
        DB::table('steps')->where('id', 101)->update(['type' => 'terminal']);

        // History must be unchanged: last quarter's cycle times cannot move because
        // someone edited a step today.
        expect(DB::table('project_step_movements')->where('seq', 1)->value('to_step_type'))
            ->toBe('active');
    });
});

describe('member removal does not destroy work', function () {

    // ═══════════════════════════════════════════════════════════════════════════
    // REGRESSION TEST — VERIFICATION.md data-model-1.
    //
    // The original design gave tasks.assignee_user_id a composite FK to
    // tracker_members ON DELETE CASCADE, by analogy with the assignee/watcher pivot
    // tables. tasks is an entity table, so the cascade deleted the WORK ITEM rather
    // than the assignment — reproduced live: task rows vanished with deleted_at
    // never set, no audit row, and tasks_total left permanently wrong.
    //
    // If someone "tidies up" this schema by restoring that FK for symmetry with the
    // pivots, this test is what stops it reaching production.
    // ═══════════════════════════════════════════════════════════════════════════
    it('does NOT delete tasks when their assignee is removed from the tracker', function () {
        expect(DB::table('tasks')->count())->toBe(2);

        DB::table('tracker_members')->where('tracker_id', 1)->where('user_id', 10)->delete();

        expect(DB::table('tasks')->count())->toBe(2)
            ->and(DB::table('tasks')->where('id', 1)->exists())->toBeTrue()
            ->and(DB::table('tasks')->where('id', 1)->value('deleted_at'))->toBeNull();
    });

    it('does remove the assignment itself, since that row IS the assignment', function () {
        DB::table('project_assignees')->insert([
            'tracker_id' => 1, 'project_id' => 1, 'user_id' => 10, 'assigned_at' => now(),
        ]);

        DB::table('tracker_members')->where('tracker_id', 1)->where('user_id', 10)->delete();

        // FR-2.4: removal takes effect immediately. You cannot hold work in a tracker
        // you can no longer see.
        expect(DB::table('project_assignees')->where('user_id', 10)->exists())->toBeFalse();
    });
});

describe('notification outbox coalescing', function () {

    it('collapses repeated pending notifications for the same project into one row', function () {
        $row = [
            'user_id' => 11, 'tracker_id' => 1, 'project_id' => 1, 'event_type' => 'project.moved',
            'payload' => '{}', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('notification_outbox')->insert($row);

        // FR-7.8 — three drags inside the window must not produce three emails.
        $errno = rejectedWithErrno(fn () => DB::table('notification_outbox')->insert($row));

        expect($errno)->toBe(1062);
    });

    it('releases the coalesce key once the notification has been sent', function () {
        $row = [
            'user_id' => 11, 'tracker_id' => 1, 'project_id' => 1, 'event_type' => 'project.moved',
            'payload' => '{}', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('notification_outbox')->insert($row);
        DB::table('notification_outbox')->where('user_id', 11)->update(['status' => 'sent', 'sent_at' => now()]);

        // A later move must be able to notify again.
        DB::table('notification_outbox')->insert($row);

        expect(DB::table('notification_outbox')->count())->toBe(2);
    });
});

describe('health and account invariants', function () {

    it('refuses an On Hold project that is not manually set', function () {
        // FR-4.6 — On Hold is always deliberate, and suppresses auto-stall. An
        // automatic On Hold would silently hide a project from the watchlist.
        $errno = rejectedWithErrno(fn () => DB::table('projects')->where('id', 1)
            ->update(['health' => 'on_hold', 'health_source' => 'auto']));

        expect($errno)->toBe(3819);   // CHECK constraint violation
    });

    it('refuses a second break-glass account', function () {
        DB::table('users')->insert([
            'id' => 1, 'name' => 'Break Glass', 'email' => 'break-glass@local',
            'auth_provider' => 'local', 'password' => bcrypt('x'), 'is_break_glass' => true,
            'role' => 'admin', 'status' => 'active', 'timezone' => 'Asia/Manila',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // NFR-S8 — a second password-bearing admin created by a bug or a compromised
        // session is a security failure, not a data-quality one.
        $errno = rejectedWithErrno(fn () => DB::table('users')->insert([
            'id' => 2, 'name' => 'Second', 'email' => 'second@local',
            'auth_provider' => 'local', 'password' => bcrypt('x'), 'is_break_glass' => true,
            'role' => 'admin', 'status' => 'active', 'timezone' => 'Asia/Manila',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($errno)->toBe(1062);
    });

    it('refuses a Google account that carries a password', function () {
        $errno = rejectedWithErrno(fn () => DB::table('users')->insert([
            'id' => 3, 'name' => 'Hybrid', 'email' => 'hybrid@gmail.com',
            'auth_provider' => 'google', 'password' => bcrypt('x'),
            'role' => 'member', 'status' => 'active', 'timezone' => 'Asia/Manila',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($errno)->toBe(3819);
    });

    it('reuses an archived tracker name but refuses a duplicate live one', function () {
        $errno = rejectedWithErrno(fn () => DB::table('trackers')->insert([
            'public_id' => str_repeat('C', 26), 'name' => 'IT Technical',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect($errno)->toBe(1062);

        DB::table('trackers')->where('id', 1)->update(['archived_at' => now()]);

        DB::table('trackers')->insert([
            'public_id' => str_repeat('D', 26), 'name' => 'IT Technical',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(DB::table('trackers')->where('name', 'IT Technical')->count())->toBe(2);
    });
});
