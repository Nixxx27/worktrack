<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Steps (board columns, owned per tracker) and Projects (the cards that move).
 *
 * Both tables carry UNIQUE (id, tracker_id). Those keys look redundant next to the
 * primary key and are not: they are the indexes referenced by every child table's
 * composite foreign key, which is what makes cross-tracker data structurally
 * impossible rather than merely filtered (docs/ARCHITECTURE.md §3.2, verified live
 * against MySQL 8.0.33 — errno 1452).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_id')->constrained('trackers')->restrictOnDelete();
            $table->string('name', 80);

            // The semantic type drives EVERY metric. Cross-tracker reporting groups by
            // this, never by step name, because each tracker names its columns
            // differently and "In Progress" in one is not comparable to "Development"
            // in another (FR-3.7 / FR-8.8).
            //   intake   = waiting, not working
            //   active   = counts toward cycle time
            //   terminal = stops the clock
            $table->enum('type', ['intake', 'active', 'terminal']);

            $table->char('color', 7)->nullable();
            $table->unsignedInteger('position')->default(0);

            // FR-3.8: visual warning only, never a hard block on drop. A hard block
            // would need a server-side count on every move, against NFR-P3.
            $table->unsignedSmallInteger('wip_limit')->nullable();

            $table->string('description', 255)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tracker_id', 'archived_at', 'position'], 'idx_steps_board_order');
            $table->index(['tracker_id', 'type'], 'idx_steps_type');   // FR-3.3 invariant check

            // No unique on (tracker_id, position): MySQL has no deferred constraints,
            // so a unique position turns every reorder into a dance around collisions.
            // Positions are renormalized inside the reorder transaction instead.
        });

        DB::statement('ALTER TABLE steps ADD UNIQUE KEY uk_steps_id_tracker (id, tracker_id)');

        // Two LIVE steps in one tracker cannot share a name; an archived "Testing"
        // does not block creating a new one (FR-3.1 allows archive-then-recreate).
        DB::statement('
            ALTER TABLE steps
            ADD COLUMN name_active VARCHAR(80)
                GENERATED ALWAYS AS (IF(archived_at IS NULL, name, NULL)) STORED,
            ADD UNIQUE KEY uk_steps_name_active (tracker_id, name_active)
        ');

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('tracker_id')->constrained('trackers')->restrictOnDelete();

            // step_id gets a COMPOSITE fk below, so a project can never sit in another
            // tracker's step. Verified live: errno 1452.
            $table->unsignedBigInteger('step_id');

            // Midpoint insertion: a drop writes ONE row. Contiguous integers would
            // rewrite every card below the drop point — 250 rows at the 500-card
            // target, inside the request the optimistic UI then has to reconcile
            // (DD-17, NFR-P3). DECIMAL not DOUBLE because binary floating point loses
            // the midpoint after ~50 same-gap insertions and two cards silently collide.
            $table->decimal('board_position', 20, 10)->default(0);

            // FR-4.1 / NFR-U1: name is the ONLY required field. Adoption depends on
            // creating a project taking under 15 seconds.
            $table->string('name', 200);
            $table->mediumText('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();

            // Membership is enforced in the application layer, not by a composite FK.
            // MySQL forbids ON DELETE SET NULL when a referenced column is NOT NULL,
            // and RESTRICT would block legitimate member removal (DD-11).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();

            // Health is independent of which column the card sits in (D5). A project
            // keeps its real step while flagged, so you see "stuck in In Progress for
            // 21 days" rather than losing its position.
            $table->enum('health', ['on_track', 'at_risk', 'stalled', 'on_hold'])->default('on_track');
            $table->enum('health_source', ['auto', 'manual'])->default('auto');   // reconciled name, §9.2
            $table->string('health_reason', 500)->nullable();
            $table->dateTime('health_set_at')->nullable();
            $table->foreignId('health_set_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // One stall email per episode, not one per night for six weeks (DD-5).
            // Cleared whenever health leaves 'stalled'.
            $table->dateTime('stall_notified_at')->nullable();

            // ---------------------------------------------------------------
            // CRITICAL FIX — VERIFICATION.md metrics-4.
            // last_activity_at is NOT NULL, seeded to created_at.
            // As originally designed it was nullable, and the stall predicate
            // `TIMESTAMPDIFF(SECOND, last_activity_at, NOW()) >= threshold`
            // evaluates to NULL — never selecting the row — when it is NULL.
            // A project created and never touched would therefore be permanently
            // invisible to stall detection: the single most rotten item in the
            // system, and the one item the system structurally could not see.
            // That is an exact inversion of the product's primary goal.
            // ---------------------------------------------------------------
            $table->dateTime('last_activity_at');
            $table->string('last_activity_type', 40)->default('created');
            $table->foreignId('last_activity_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalized read model. Every column here is derivable from
            // project_step_movements and rebuildable by an idempotent artisan
            // command — that command is the drift detector, not an afterthought.
            // Without these the board is 3 correlated subqueries × 500 cards (NFR-P1).
            $table->enum('current_step_type', ['intake', 'active', 'terminal'])->default('intake');
            $table->dateTime('current_step_entered_at');
            $table->dateTime('first_active_at')->nullable();
            $table->dateTime('first_terminal_at')->nullable();
            $table->unsignedInteger('cycle_time_seconds')->nullable();   // NULL while in flight; never coerced to 0
            $table->unsignedInteger('lead_time_seconds')->nullable();
            $table->boolean('skipped_active')->default(false);           // intake -> terminal, never worked
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->unsignedSmallInteger('tasks_total')->default(0);
            $table->unsignedSmallInteger('tasks_done')->default(0);
            $table->unsignedSmallInteger('attachments_count')->default(0);
            $table->unsignedSmallInteger('comments_count')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Each index is tied to a named query rather than added speculatively.
            $table->index(['tracker_id', 'archived_at', 'step_id', 'board_position'], 'idx_projects_board');
            $table->index(['tracker_id', 'archived_at', 'current_step_entered_at'], 'idx_projects_aging');
            $table->index(['tracker_id', 'archived_at', 'health', 'last_activity_at'], 'idx_projects_watchlist');
            $table->index(['archived_at', 'last_activity_at', 'health'], 'idx_projects_watchlist_global');
            $table->index(['tracker_id', 'first_terminal_at'], 'idx_projects_completed');
            $table->index(['department_id', 'archived_at', 'current_step_entered_at'], 'idx_projects_dept');
            $table->index(['owner_user_id', 'archived_at'], 'idx_projects_owner');
            $table->index(['tracker_id', 'name'], 'idx_projects_name');
        });

        // Referenced index for every child table's composite FK.
        DB::statement('ALTER TABLE projects ADD UNIQUE KEY uk_projects_id_tracker (id, tracker_id)');

        // A project can never sit in a step belonging to a different tracker.
        DB::statement('
            ALTER TABLE projects
            ADD CONSTRAINT fk_projects_step
            FOREIGN KEY (step_id, tracker_id) REFERENCES steps (id, tracker_id)
        ');

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT chk_projects_hold_is_manual
                CHECK (health <> 'on_hold' OR health_source = 'manual'),
            ADD CONSTRAINT chk_projects_tasks_done
                CHECK (tasks_done <= tasks_total)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('steps');
    }
};
