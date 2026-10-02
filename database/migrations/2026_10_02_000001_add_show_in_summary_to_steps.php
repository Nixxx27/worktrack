<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-step flag: list this column's cards in the dashboard's schedule summary.
 *
 * A FLAG PER STEP for the same reason as requires_due_date: "the work in progress"
 * is a per-tracker decision. One tracker runs two working columns ("In Progress" and
 * "In Progress (Top Priority)"), another may want "To Do" watched too because that is
 * where commitments are made.
 *
 * Backfilled ON for every active-type step, so the summary shows the in-flight work
 * the moment this ships instead of opening empty and waiting for an admin to find a
 * setting. Intake and terminal steps stay off until someone opts them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->boolean('show_in_summary')->default(false)->after('requires_due_date');
        });

        DB::table('steps')->where('type', 'active')->update(['show_in_summary' => true]);
    }

    public function down(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->dropColumn('show_in_summary');
        });
    }
};
