<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification outbox and preferences, settings, and signup abuse controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->json('value');
            $table->string('description', 500)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();

            // Digest defaults ON for admins (docs/ARCHITECTURE.md §8). Admins receive
            // every step change in every tracker, so they are the only people
            // guaranteed to drown — the release valve should be their default, not an
            // opt-in they discover after the Gmail cap is hit.
            $table->enum('delivery_mode', ['immediate', 'digest'])->default('immediate');
            $table->unsignedTinyInteger('digest_hour')->default(8);   // local time, per users.timezone

            $table->json('muted_events')->nullable();                 // per-event toggles (FR-7.7)
            $table->timestamps();
        });

        // Per-tracker mute lives in its OWN table, deliberately NOT as a column on
        // tracker_members (DD-12). Admins have no membership row for most trackers, so
        // a flag on the membership row would be unreachable for exactly the users
        // drowning in mail from trackers they do not belong to.
        Schema::create('user_tracker_mutes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tracker_id')->constrained('trackers')->cascadeOnDelete();
            $table->dateTime('muted_at');

            $table->primary(['user_id', 'tracker_id']);
        });

        // ── OUTBOX ──────────────────────────────────────────────────────────────
        // Sits between domain events and Laravel's queue. Three requirements converge
        // here and none is satisfiable by the jobs table alone: coalescing (FR-7.8),
        // digest batching (FR-7.7), and admin-visible failures (FR-7.6 / FR-10.5).
        //
        // ═══════════════════════════════════════════════════════════════════════
        // CRITICAL — VERIFICATION.md data-model-3 (write ordering).
        // Outbox rows MUST be INSERTed inside the SAME transaction as the movement,
        // activity and audit rows. Only the queue DISPATCH happens after commit.
        // As originally designed the inserts came after commit, which is a dual-write:
        // a crash in that window left the move durably recorded and visible on the
        // board with no notification row anywhere — not in outbox, not in failed_jobs,
        // not in the FR-10.5 pending count. The failure was invisible by construction,
        // because the symptom is the ABSENCE of a row rather than the presence of a
        // failed one. It was worse for stall emails, where stall_notified_at had
        // already been set, permanently suppressing any retry for that episode.
        //
        // CRITICAL — VERIFICATION.md data-model-2 (authorization freshness).
        // Recipients are resolved and frozen at EVENT time, but delivery can be up to
        // 24h later via digest. The dispatcher MUST re-assert, immediately before
        // send, that the recipient is still active AND (is an admin OR still has a
        // tracker_members row) AND has no applicable mute — transitioning the row to
        // 'suppressed' rather than sending if not. Snapshotting CONTENT is correct;
        // snapshotting AUTHORIZATION is the bug.
        //
        // NOTE — the verification report suggested enforcing this with a composite FK
        // (tracker_id, user_id) -> tracker_members ON DELETE CASCADE. That fix is
        // WRONG here and is deliberately not applied: admins legitimately receive
        // notifications for trackers they are NOT members of (FR-7.2/FR-7.3), so a
        // mandatory FK to tracker_members would make every admin notification
        // unwritable. Re-assertion at send time is the correct control.
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();

            // NOTE: none of user_id / tracker_id / project_id may use a cascading
            // referential action. MySQL forbids ON DELETE CASCADE (or SET NULL) on any
            // base column of a STORED generated column, and all three feed coalesce_key
            // below. Attempting it fails at migrate time with errno 1215.
            // This costs nothing: users are never deleted (AUTH-D27) and projects and
            // trackers are archived rather than hard-deleted (FR-2.7, FR-4.9), so there
            // is no delete for a cascade to follow. Outbox rows are drained or
            // suppressed by the dispatcher, not by referential action.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('tracker_id')->nullable();     // nullable: signup/approval mail has no tracker
            $table->unsignedBigInteger('project_id')->nullable();

            $table->string('event_type', 60);                         // project.moved, user.approved, project.stalled, ...
            $table->json('payload');                                  // content snapshot — a later rename must not rewrite a sent email

            $table->enum('delivery_mode', ['immediate', 'digest'])->default('immediate');
            $table->enum('status', ['pending', 'sending', 'sent', 'failed', 'suppressed'])->default('pending');
            $table->string('suppressed_reason', 120)->nullable();     // not_member | not_active | muted

            $table->dateTime('available_at');                         // coalesce window end, or the digest hour
            $table->dateTime('sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['status', 'available_at'], 'idx_outbox_ready');            // the poller
            $table->index(['user_id', 'delivery_mode', 'status', 'available_at'], 'idx_outbox_digest');
            $table->index(['status', 'created_at'], 'idx_outbox_health');             // FR-10.5 queue health
        });

        DB::statement('
            ALTER TABLE notification_outbox
            ADD CONSTRAINT fk_outbox_tracker FOREIGN KEY (tracker_id) REFERENCES trackers (id),
            ADD CONSTRAINT fk_outbox_project FOREIGN KEY (project_id) REFERENCES projects (id)
        ');

        // Coalescing (FR-7.8): three drags of the same project inside the window must
        // produce ONE email describing the net move, not three. The generated column is
        // non-NULL only while the row is pending, and MySQL treats multiple NULLs in a
        // unique index as non-duplicates — so a second event UPDATEs the pending row
        // instead of inserting, and already-sent rows stop participating.
        // Laravel's ShouldBeUnique cannot express this: it blocks duplicate dispatch
        // but cannot merge payloads, and cannot schedule a digest window.
        DB::statement("
            ALTER TABLE notification_outbox
            ADD COLUMN coalesce_key VARCHAR(190)
                GENERATED ALWAYS AS (
                    IF(status = 'pending',
                       CONCAT(user_id, ':', event_type, ':', IFNULL(project_id, 0)),
                       NULL)
                ) STORED,
            ADD UNIQUE KEY uk_outbox_coalesce (coalesce_key)
        ");

        // ── SIGNUP ABUSE CONTROLS ───────────────────────────────────────────────
        // Signup is open to any Google account (D6), because the dev team uses
        // personal Gmail. That means the approval queue is reachable from the public
        // internet and needs its own controls (FR-1.9, NFR-S4).
        Schema::create('blocked_emails', function (Blueprint $table) {   // reconciled name, §9.2
            $table->id();
            // Canonicalized: lowercased for all domains, plus dot- and +tag-stripping
            // for gmail.com / googlemail.com only, so a rejected person cannot re-apply
            // as first.last+x@gmail.com (AUTH-D21).
            $table->string('canonical_email')->unique();
            $table->string('original_email');
            $table->string('google_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('signup_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45);
            $table->string('email')->nullable();
            $table->string('google_id')->nullable();
            $table->enum('outcome', [
                'created', 'blocked_email', 'rate_limited', 'link_denied',
                'email_unverified', 'returning_user',
            ]);
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at');

            $table->index(['ip_address', 'created_at'], 'idx_signup_ip');
            $table->index(['outcome', 'created_at'], 'idx_signup_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_attempts');
        Schema::dropIfExists('blocked_emails');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('user_tracker_mutes');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('settings');
    }
};
