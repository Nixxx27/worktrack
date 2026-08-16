<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags (FR-4.2) — free-typed labels on project cards.
 *
 * Tags are owned PER TRACKER, not globally, for the same reason steps are (FR-2.2):
 * a global tag list is a cross-tracker read surface, and every name in it leaks the
 * vocabulary — often the project names — of trackers the reader cannot see. That is
 * the exact shape of FR-2.9 leak the tracker switcher already suffered once
 * (VERIFICATION.md authorization-1). Scoping them per tracker keeps the isolation
 * boundary a table-level property rather than a filter someone must remember.
 *
 * The cost is that "Urgent" in IT Technical and "Urgent" in Systems Development are
 * different rows. That is the same deliberate trade already made for step names
 * (FR-3.7): same-name things across trackers are not assumed to be equivalent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracker_id')->constrained('trackers')->restrictOnDelete();

            $table->string('name', 40);

            // Assigned round-robin at creation from a fixed palette so a board is
            // legible without anyone configuring anything. Nullable so a future
            // recolour UI has somewhere to write.
            $table->char('color', 7)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Free-typed tags are matched case-insensitively at write time
            // (TagRepository::resolve lowercases before lookup), so the collation's
            // own case-insensitivity here is a backstop rather than the mechanism.
            $table->unique(['tracker_id', 'name'], 'uk_tags_tracker_name');
        });

        // Referenced index for the pivot's composite FK below.
        DB::statement('ALTER TABLE tags ADD UNIQUE KEY uk_tags_id_tracker (id, tracker_id)');

        // A pivot, so CASCADE is correct on both sides: deleting a tag removes the
        // labelling, not the project, and vice versa. The composite FKs make a
        // cross-tracker tagging structurally impossible rather than merely filtered —
        // a crafted POST carrying another tracker's tag_id hits errno 1452.
        Schema::create('project_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('tracker_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('tag_id');
            $table->dateTime('tagged_at');
            $table->foreignId('tagged_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->primary(['project_id', 'tag_id']);
            $table->index(['tag_id', 'tracker_id'], 'idx_project_tags_by_tag');   // FR-3.6 filter by tag
        });

        DB::statement('
            ALTER TABLE project_tags
            ADD CONSTRAINT fk_project_tags_project
                FOREIGN KEY (project_id, tracker_id) REFERENCES projects (id, tracker_id) ON DELETE CASCADE,
            ADD CONSTRAINT fk_project_tags_tag
                FOREIGN KEY (tag_id, tracker_id) REFERENCES tags (id, tracker_id) ON DELETE CASCADE
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tags');
        Schema::dropIfExists('tags');
    }
};
