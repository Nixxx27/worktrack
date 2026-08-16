<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-step gate: entering this column requires the project to have a due date.
 *
 * A FLAG PER STEP, not a rule derived from step type. The obvious phrasing —
 * "leaving intake requires a due date" — cannot express the rule people actually
 * want: the default step set is Backlog(intake) → New(intake) → In Progress(active)
 * → Done(terminal), and "Backlog → To Do" is intake → intake, so a type-based rule
 * would not fire on the one move that matters. Steps are also per tracker (FR-2.2)
 * and each tracker names and shapes its workflow differently, so where the
 * commitment gets made is a per-tracker decision by definition.
 *
 * Positional phrasings ("the first non-intake step") were rejected for a second
 * reason: FR-3.1 lets admins reorder, so the gate would silently relocate itself
 * when a column moved. A flag rides with the step through renames and reorders.
 *
 * Default false: adding this column changes nothing until an admin opts a step in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            // Deliberately NOT a database constraint on projects.target_date. FR-4.1
            // keeps that column nullable on purpose — an unrecorded project is the one
            // failure mode this product cannot tolerate, so imports, seeders and
            // ProjectService::create must all still accept a name alone. This is a
            // workflow gate on one transition, which is a different claim from "a
            // project may not exist without a due date".
            $table->boolean('requires_due_date')->default(false)->after('wip_limit');
        });
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropColumn('requires_due_date');
        });
    }
};
