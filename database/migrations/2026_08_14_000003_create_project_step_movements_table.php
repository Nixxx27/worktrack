<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE SOURCE OF TRUTH FOR EVERY METRIC (FR-4.8, FR-8.7).
 *
 * One row per step occupancy, stored as a CLOSED INTERVAL. This is the only
 * non-reconstructable asset in the system: projects' denormalized caches and all
 * reporting rebuild from this table, and this table rebuilds from nothing. Back it
 * up accordingly (NFR-R4), and make restore tests actually replay it.
 *
 * SETTLED: one table, not two. The metrics design proposed an append-only log plus
 * a derived `project_step_residencies` projection; the data model proposed this
 * single closed-interval table. One table won (docs/ARCHITECTURE.md §9.1 C1):
 * exited_at is written exactly once by the recorder and never by a user, so
 * "history is never edited by users" holds, and there are not two things that can
 * silently diverge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_step_movements', function (Blueprint $table) {
            $table->id();

            // Denormalized for scoping. Every read path filters on tracker_id with no
            // join, and the composite FK below means a row pointing at the wrong
            // tracker cannot be written even by buggy application code.
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');

            // 1-based ordinal within the project. Gives deterministic ordering when
            // two moves land in the same second, and makes "the previous row" a key
            // lookup for FR-7.2's "how long it sat in the previous step".
            $table->unsignedInteger('seq');

            $table->unsignedBigInteger('from_step_id')->nullable();   // NULL only on seq=1 (creation)
            $table->enum('from_step_type', ['intake', 'active', 'terminal'])->nullable();
            $table->unsignedBigInteger('to_step_id');

            // FROZEN SNAPSHOT, not a live join to steps.type.
            // Admins may retype a step at any time (FR-3.1). If reports joined live,
            // retyping "Testing" from active to intake in 2027 would silently rewrite
            // 2026's cycle-time history — metrics changing with no project having moved.
            $table->enum('to_step_type', ['intake', 'active', 'terminal']);

            // ---------------------------------------------------------------
            // CRITICAL FIX — VERIFICATION.md metrics-1.
            // Department is snapshotted onto the movement row.
            // Department is editable per project (FR-4.1), so without this snapshot
            // the history is NOT a pure function of the movement log: a rebuild
            // would stamp the project's CURRENT department onto every historical
            // row, moving 40 days of January "Networking" aging into
            // "Infrastructure". Two runs of the same FR-8.1 report would disagree
            // across a rebuild, and the rebuild-and-compare verifier would not flag
            // it because the wrong result is self-consistent.
            // ---------------------------------------------------------------
            $table->unsignedBigInteger('department_id')->nullable();

            $table->foreignId('moved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // 'system' covers step-retype corrections, which must be emitted as real
            // movements so open intervals re-type correctly (VERIFICATION.md metrics-3).
            // 'import' lets backfilled history be excluded from throughput without
            // deleting it.
            $table->enum('reason', ['created', 'user_move', 'import', 'system', 'correction'])
                ->default('user_move');

            $table->dateTime('entered_at');
            $table->dateTime('exited_at')->nullable();   // NULL = the project is in this step right now

            $table->timestamps();

            $table->index(['project_id', 'seq'], 'idx_movements_project_seq');
            $table->index(['to_step_id', 'exited_at'], 'idx_movements_step_closed');
            $table->index(['tracker_id', 'to_step_type', 'entered_at'], 'idx_movements_tracker_type');
            $table->index(['tracker_id', 'entered_at'], 'idx_movements_tracker_entered');
            $table->index(['moved_by_user_id', 'entered_at'], 'idx_movements_actor');
            $table->index(['department_id', 'to_step_type', 'entered_at'], 'idx_movements_dept');
        });

        // Composite FKs: a movement cannot reference a project or a step in another tracker.
        DB::statement('
            ALTER TABLE project_step_movements
            ADD CONSTRAINT fk_movements_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id),
            ADD CONSTRAINT fk_movements_to_step
                FOREIGN KEY (to_step_id, tracker_id) REFERENCES steps (id, tracker_id),
            ADD CONSTRAINT fk_movements_from_step
                FOREIGN KEY (from_step_id, tracker_id) REFERENCES steps (id, tracker_id),
            ADD CONSTRAINT fk_movements_department
                FOREIGN KEY (department_id) REFERENCES departments (id)
        ');

        // duration_seconds is GENERATED, never application-written. It therefore
        // cannot drift from its own timestamps, and a crash between writing exited_at
        // and writing a duration cannot leave a permanently wrong number in the one
        // table the requirements call the source of truth.
        //
        // open_project_id is non-NULL only while the row is open, so a UNIQUE index on
        // it makes "a project is in exactly one step" a database invariant rather than
        // a code convention. Verified live: a second open row is rejected, errno 1062.
        DB::statement('
            ALTER TABLE project_step_movements
            ADD COLUMN duration_seconds INT UNSIGNED
                GENERATED ALWAYS AS (TIMESTAMPDIFF(SECOND, entered_at, exited_at)) STORED,
            ADD COLUMN open_project_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(exited_at IS NULL, project_id, NULL)) STORED,
            ADD UNIQUE KEY uk_movements_one_open (open_project_id),
            ADD UNIQUE KEY uk_movements_project_seq (project_id, seq)
        ');

        DB::statement('
            ALTER TABLE project_step_movements
            ADD CONSTRAINT chk_movements_interval
                CHECK (exited_at IS NULL OR exited_at >= entered_at),
            ADD CONSTRAINT chk_movements_first_row
                CHECK (seq > 1 OR from_step_id IS NULL)
        ');

        // Covering index for FR-8.1 "average time each step holds a project".
        // Added after the generated column exists so duration_seconds can be included.
        DB::statement('
            CREATE INDEX idx_movements_step_duration
            ON project_step_movements (to_step_id, exited_at, duration_seconds)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_step_movements');
    }
};
