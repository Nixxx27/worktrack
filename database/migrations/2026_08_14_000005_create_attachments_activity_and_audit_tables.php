<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments (R2), the per-project activity feed, and the admin audit log.
 *
 * project_activities and audit_logs are deliberately SEPARATE tables (DD-10).
 * Merging them would put admin-only rows (logins, break-glass attempts, role
 * changes, settings edits) into a table tracker members read, which would make the
 * NFR-S3 isolation boundary a row-level filter over a mixed table rather than a
 * table-level grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();

            // Denormalized so the FR-6.4 authorization check on signed-URL issuance is
            // one indexed lookup with no branch, rather than traversing
            // attachment -> attachable -> project -> tracker at request time. That
            // traversal is exactly the shape of code where an isolation bug hides.
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id')->nullable();   // task attachments, not a polymorphic morph (DD-8)

            // Unguessable and leaks nothing about the original filename (FR-6.6).
            // Format: attachments/{tracker_ulid}/{project_ulid}/{YYYY}/{MM}/{ulid}-{32 hex}
            $table->string('object_key', 512)->unique();
            $table->string('disk', 32)->default('r2');

            $table->string('original_filename', 255);
            $table->string('extension', 20)->nullable();   // display only, never trusted for validation
            $table->string('mime_type', 160);              // sniffed from magic bytes, not the extension (NFR-S9)
            $table->unsignedBigInteger('size_bytes');

            // The record is created 'pending' BEFORE the R2 PUT and promoted to
            // 'available' only after it succeeds, so nothing but 'available' is ever
            // listed or signable (FR-6.8). Deletion moves to 'deleting' first and the
            // row is only removed once R2 confirms, so an R2 outage leaves a retryable
            // tombstone rather than an orphaned object nobody can find (FR-6.7).
            $table->enum('status', ['pending', 'available', 'failed', 'deleting'])->default('pending');

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('purged_at')->nullable();   // distinguishes "user deleted" from "reaped by policy"
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'status', 'deleted_at'], 'idx_attachments_project');
            $table->index(['task_id', 'status'], 'idx_attachments_task');
            $table->index(['status', 'created_at'], 'idx_attachments_sweeper');   // stale-pending reaper
            $table->index(['tracker_id', 'deleted_at'], 'idx_attachments_tracker');
        });

        DB::statement('
            ALTER TABLE attachments
            ADD CONSTRAINT fk_attachments_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id),
            ADD CONSTRAINT fk_attachments_task
                FOREIGN KEY (task_id, tracker_id) REFERENCES tasks (id, tracker_id)
        ');

        // Per-project feed, readable by tracker members (FR-4.5, FR-5.5).
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 40);          // step_move, task_created, comment, attachment_added, field_edit, ...
            $table->json('payload')->nullable();

            // FR-4.7 defines activity as a closed list and explicitly excludes viewing.
            // This flag is what lets the feed carry rows that are NOT activity (e.g. the
            // stall job's own writes) without them resetting the stall clock.
            $table->boolean('counts_as_activity')->default(true);

            $table->timestamps();

            $table->index(['project_id', 'created_at'], 'idx_activities_project');
            $table->index(['project_id', 'counts_as_activity', 'created_at'], 'idx_activities_fallback');
        });

        DB::statement('
            ALTER TABLE project_activities
            ADD CONSTRAINT fk_activities_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id)
        ');

        // Admin-only, append-only. No updated_at: nothing here is ever modified.
        // Deliberately NOT tracker-scoped by the global scope (AUTHZ-18) — it carries
        // a nullable tracker_id purely so FR-9.3 can filter by it, and access is gated
        // by policy plus an admin-only route group.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);                     // auth.break_glass.attempt, user.approved, tracker.archived, ...
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('tracker_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('context')->nullable();              // never contains credentials, in any form
            $table->dateTime('created_at');

            $table->index(['created_at'], 'idx_audit_time');
            $table->index(['actor_user_id', 'created_at'], 'idx_audit_actor');
            $table->index(['action', 'created_at'], 'idx_audit_action');
            $table->index(['tracker_id', 'created_at'], 'idx_audit_tracker');
            $table->index(['target_type', 'target_id'], 'idx_audit_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('project_activities');
        Schema::dropIfExists('attachments');
    }
};
