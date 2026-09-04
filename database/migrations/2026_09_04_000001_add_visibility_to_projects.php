<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-4.11 — "Only me" cards.
 *
 * One column, because privacy here is a property of the card and not a new kind of
 * container: a private card keeps its tracker, its step, its position and its
 * history, so every composite foreign key and every index built in
 * 2026_08_14_000002 stays exactly as correct as it was.
 *
 * The owner is the audience — there is no private_user_id column, deliberately.
 * owner_user_id already means "the person accountable for this", it is already
 * restricted to tracker members (FR-4.3), and a second column naming a person would
 * be a second thing that can disagree with the first. ProjectService refuses to move
 * ownership of a private card for the same reason.
 *
 * DEFAULT 'tracker' so every existing row keeps the behaviour it has today, and so a
 * writer that has never heard of this column cannot accidentally create a hidden
 * card. Privacy has to be asked for.
 *
 * No new index. The predicate the scope adds is
 * `(visibility = 'tracker' OR owner_user_id = ?)`, which is low-selectivity and runs
 * against a set the board's existing idx_projects_board has already narrowed to one
 * tracker's live cards. An index leading with a two-value column would be read but
 * not used, and would cost a write on every card movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('visibility', ['tracker', 'private'])
                ->default('tracker')
                ->after('description');
        });

        // ═══════════════════════════════════════════════════════════════════════════
        // NO CHECK CONSTRAINT HERE, AND NOT FOR WANT OF TRYING.
        //
        // The invariant worth enforcing is "a private card has an owner": the scope
        // matches on owner_user_id, so a NULL owner hides the row from every user
        // INCLUDING its creator, with no error and no route back through the UI. And
        // owner_user_id is nullable by DD-11, so that state is reachable rather than
        // theoretical.
        //
        //     ADD CONSTRAINT chk_projects_private_has_owner
        //         CHECK (visibility <> 'private' OR owner_user_id IS NOT NULL)
        //
        // MySQL 8 refuses it — errno 3823: "Column 'owner_user_id' cannot be used in a
        // check constraint: needed in a foreign key constraint referential action."
        // The column is the target of an ON DELETE SET NULL, and the engine will not
        // let a CHECK forbid the value its own FK action is allowed to write.
        //
        // So the guard lives in ProjectService::changeOwner(), which refuses to move or
        // clear the owner of a private card. That is a weaker control than a constraint
        // and it is named as such here, so nobody has to rediscover errno 3823 to find
        // out why the obvious belt is missing.
        // ═══════════════════════════════════════════════════════════════════════════
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
