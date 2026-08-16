<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project people (assignees, watchers) and Tasks.
 *
 * This migration is where the most dangerous verified bug was fixed. Read the
 * comment on tasks.assignee_user_id before changing anything here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── PIVOT TABLES ────────────────────────────────────────────────────────
        // These two ARE pivots, so the composite FK to tracker_members with
        // ON DELETE CASCADE is correct: removing someone from a tracker should
        // remove their ASSIGNMENT, and the assignment is the whole row. FR-4.3
        // ("assignees can only be members of that tracker") becomes a database
        // invariant that a crafted POST cannot defeat, and FR-2.4's "removal takes
        // effect immediately" is enforced by the engine.
        //
        // History is untouched: movements, comments and activities attribute by
        // plain user_id and are not cascaded.
        Schema::create('project_assignees', function (Blueprint $table) {
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('assigned_at');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->primary(['project_id', 'user_id']);
            $table->index(['user_id', 'tracker_id'], 'idx_assignees_workload');   // FR-8.4 per-person workload
        });

        DB::statement('
            ALTER TABLE project_assignees
            ADD CONSTRAINT fk_assignees_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id) ON DELETE CASCADE,
            ADD CONSTRAINT fk_assignees_membership
                FOREIGN KEY (tracker_id, user_id) REFERENCES tracker_members (tracker_id, user_id) ON DELETE CASCADE
        ');

        Schema::create('project_watchers', function (Blueprint $table) {
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('watched_at');

            $table->primary(['project_id', 'user_id']);
            $table->index('user_id', 'idx_watchers_user');
        });

        DB::statement('
            ALTER TABLE project_watchers
            ADD CONSTRAINT fk_watchers_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id) ON DELETE CASCADE,
            ADD CONSTRAINT fk_watchers_membership
                FOREIGN KEY (tracker_id, user_id) REFERENCES tracker_members (tracker_id, user_id) ON DELETE CASCADE
        ');

        // ── TASKS ───────────────────────────────────────────────────────────────
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');

            $table->string('title', 200);
            $table->text('description')->nullable();

            // ═══════════════════════════════════════════════════════════════════
            // CRITICAL FIX — VERIFICATION.md data-model-1. REPRODUCED LIVE.
            //
            // The original design gave this column a composite FK
            //   (tracker_id, assignee_user_id) -> tracker_members ON DELETE CASCADE
            // by analogy with the pivot tables above. tasks is an ENTITY table, not a
            // pivot, so the cascade deleted the WORK ITEM, not the assignment.
            //
            // Reproduced against MySQL 8.0.33 in this project: removing a member
            // hard-deleted their task rows — deleted_at never set, no application code
            // invoked, no audit row written, and projects.tasks_total left reading 8
            // while 7 tasks existed, so the card shows "3/8" forever and the
            // tasks_done <= tasks_total CHECK can be violated on the next completion.
            // It also violated FR-2.4 ("removal does not delete that person's history").
            //
            // Worse, it was nondeterministic from the admin's seat: with an attachment
            // on one of the tasks, an ON DELETE RESTRICT elsewhere turned the same
            // action into a raw errno 1451 instead.
            //
            // ON DELETE SET NULL is not available — MySQL forbids it when a referenced
            // column (tracker_id) is NOT NULL. So this is a plain FK to users, with the
            // FR-5.4 membership rule enforced in the application layer. That is the
            // same concession already made for projects.owner_user_id, for the same
            // MySQL reason.
            //
            // The member-removal transaction MUST therefore explicitly:
            //   UPDATE tasks SET assignee_user_id = NULL WHERE ...
            // and write the audit + activity rows and recompute counter caches.
            // A weekly integrity check should flag any task whose assignee is no
            // longer a tracker member, alongside the equivalent owner check.
            // ═══════════════════════════════════════════════════════════════════
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('due_date')->nullable();
            $table->boolean('is_done')->default(false);
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('position', 20, 10)->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();   // tasks DO use SoftDeletes: exclusion is the reporting-correct default (DD-3)
            $table->timestamps();

            $table->index(['project_id', 'deleted_at', 'position'], 'idx_tasks_project');
            $table->index(['assignee_user_id', 'is_done', 'completed_at'], 'idx_tasks_assignee');  // FR-8.4
            $table->index(['tracker_id', 'deleted_at'], 'idx_tasks_tracker');
        });

        DB::statement('
            ALTER TABLE tasks
            ADD UNIQUE KEY uk_tasks_id_tracker (id, tracker_id),
            ADD CONSTRAINT fk_tasks_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id)
        ');

        DB::statement('
            ALTER TABLE tasks
            ADD CONSTRAINT chk_tasks_completion
            CHECK ((is_done = 0 AND completed_at IS NULL) OR (is_done = 1 AND completed_at IS NOT NULL))
        ');

        // ── COMMENTS ────────────────────────────────────────────────────────────
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->dateTime('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['project_id', 'deleted_at', 'created_at'], 'idx_comments_project');
        });

        DB::statement('
            ALTER TABLE comments
            ADD UNIQUE KEY uk_comments_id_tracker (id, tracker_id),
            ADD CONSTRAINT fk_comments_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id)
        ');

        // Mentions are resolved once at write time. The notification dispatcher MUST
        // re-assert membership at SEND time — see VERIFICATION.md data-model-2, where
        // freezing authorization at enqueue time let a suspended ex-member receive a
        // digest naming a tracker they could no longer see.
        Schema::create('comment_mentions', function (Blueprint $table) {
            $table->unsignedBigInteger('tracker_id');
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['comment_id', 'user_id']);
            $table->index('user_id', 'idx_mentions_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('project_watchers');
        Schema::dropIfExists('project_assignees');
    }
};
