<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments and Trackers.
 *
 * A Tracker is the admin-created container with its own steps and its own member
 * list (D8). "Tracker" is the settled final name — see docs/ARCHITECTURE.md §11.
 * Renaming it after this migration runs would touch a denormalized tracker_id
 * column on eleven child tables plus every composite foreign key name.
 *
 * Both tables use explicit archived_at with NO Eloquent global scope (DD-3):
 * archived records must stay visible to reporting, so the safe failure mode is a
 * stale row appearing on a board (loud, cosmetic, reported same day) rather than a
 * silently under-counted metric (quiet, plausible, never caught).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);

            // Per-department stall override. NULL = inherit (DD-18 / M-D12).
            // Resolution order is tracker > department > global; see settings table.
            $table->unsignedTinyInteger('stall_threshold_days')->nullable();

            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        // Partial uniqueness: two LIVE departments cannot share a name, but an
        // archived name is reusable. MySQL has no filtered indexes, so a generated
        // column that is NULL when archived carries the constraint (DD-15).
        DB::statement('
            ALTER TABLE departments
            ADD COLUMN name_active VARCHAR(120)
                GENERATED ALWAYS AS (IF(archived_at IS NULL, name, NULL)) STORED,
            ADD UNIQUE KEY uk_departments_name_active (name_active)
        ');

        Schema::create('trackers', function (Blueprint $table) {
            $table->id();

            // ULID in URLs so shareable filter links do not leak how many trackers
            // exist (DD-19). This is defence in depth, not the access control —
            // the scoping layer is what denies access (NFR-S3).
            $table->char('public_id', 26)->unique();

            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->char('color', 7)->nullable();
            $table->string('icon', 40)->nullable();

            // Inherited by new projects as their default, but editable per project (FR-4.1).
            $table->foreignId('default_department_id')->nullable()
                ->constrained('departments')->restrictOnDelete();

            $table->unsignedTinyInteger('stall_threshold_days')->nullable();     // FR-10.2, wins over department
            $table->unsignedTinyInteger('stall_intake_threshold_days')->nullable(); // NULL = same fuse as active

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->dateTime('archived_at')->nullable();                          // FR-2.7, never hard-deleted
            $table->foreignId('archived_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['archived_at', 'name'], 'idx_trackers_active');        // FR-2.6 switcher
        });

        DB::statement('
            ALTER TABLE trackers
            ADD COLUMN name_active VARCHAR(120)
                GENERATED ALWAYS AS (IF(archived_at IS NULL, name, NULL)) STORED,
            ADD UNIQUE KEY uk_trackers_name_active (name_active)
        ');

        DB::statement('
            ALTER TABLE trackers
            ADD CONSTRAINT chk_trackers_stall_days
            CHECK (
                (stall_threshold_days IS NULL OR stall_threshold_days BETWEEN 1 AND 365)
            AND (stall_intake_threshold_days IS NULL OR stall_intake_threshold_days BETWEEN 1 AND 365)
            )
        ');

        // Membership. This is the visibility layer: you see a tracker only if a row
        // here exists for you, or you are an Admin (FR-2.9 / NFR-S3).
        //
        // The PRIMARY KEY (tracker_id, user_id) is deliberately ordered that way —
        // it is the index referenced by the composite foreign keys on
        // project_assignees, project_watchers and notification_outbox, which is what
        // makes "you cannot be assigned work in a tracker you cannot see" a database
        // invariant rather than a convention (DD-11).
        Schema::create('tracker_members', function (Blueprint $table) {
            $table->foreignId('tracker_id')->constrained('trackers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('added_at');

            $table->primary(['tracker_id', 'user_id']);
            $table->index('user_id', 'idx_tracker_members_user');   // "which trackers can I see" — every request
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_members');
        Schema::dropIfExists('trackers');
        Schema::dropIfExists('departments');
    }
};
