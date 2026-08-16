<?php

namespace App\Services\Projects;

use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * THE SINGLE WRITER of projects.last_activity_at.
 *
 * FR-4.7 defines "activity" as a closed list — step move, task added/completed,
 * comment, attachment upload, field edit — and explicitly excludes viewing. The
 * failure mode this class is designed against is the stall detector quietly dying
 * because something incidental keeps bumping the column: a counter-cache write, a
 * board_position rewrite during drag-and-drop, the stall job's own update. Any of
 * those would make every project look permanently fresh, and the watchlist would go
 * silently empty while work rotted.
 *
 * So: nothing else in the codebase may write last_activity_at. Not observers, not
 * touch(), not a side effect of updated_at — updated_at and last_activity_at are
 * deliberately decoupled.
 */
class ActivityRecorder
{
    /**
     * Record an activity and advance the stall clock.
     *
     * Must be called INSIDE the caller's transaction so the activity row, the clock
     * and whatever domain change caused it commit together or not at all.
     *
     * @param  bool  $countsAsActivity  false for rows that belong in the feed but must
     *                                  NOT reset the stall clock — the stall job's own
     *                                  writes being the motivating case, since counting
     *                                  them would un-stall every project it just flagged.
     */
    public function record(
        Project $project,
        string $type,
        ?User $actor = null,
        array $payload = [],
        bool $countsAsActivity = true,
    ): void {
        DB::table('project_activities')->insert([
            'tracker_id' => $project->tracker_id,
            'project_id' => $project->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'payload' => $payload ? json_encode($payload) : null,
            'counts_as_activity' => $countsAsActivity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $countsAsActivity) {
            return;
        }

        $updates = [
            'last_activity_at' => now(),
            'last_activity_type' => $type,
            'last_activity_by_user_id' => $actor?->id,
        ];

        // FR-4.6 — activity clears an AUTO-raised stall, but never overrules a human.
        // An admin who deliberately marked something Stalled should not be undone by a
        // stray comment. On Hold is likewise untouched: it is always deliberate and it
        // suppresses auto-stall entirely.
        if ($project->health === ProjectHealth::Stalled && $project->health_source === HealthSource::Auto) {
            $updates['health'] = ProjectHealth::OnTrack;
            $updates['health_reason'] = null;
            $updates['health_set_at'] = now();
            $updates['stall_notified_at'] = null;   // so the next stall episode can notify again
        }

        $project->forceFill($updates)->save();
    }
}
