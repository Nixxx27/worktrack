<?php

namespace App\Services\Projects;

use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manually setting a project's health flag (FR-4.6).
 *
 * Health is independent of which column the card is in (D5): a project keeps its real
 * step while flagged, so the board shows "stuck in In Progress for 21 days" rather
 * than losing its position to a status column.
 *
 * Two rules here are load-bearing and both are enforced by the database as well:
 *
 *   On Hold is ALWAYS manual (chk_projects_hold_is_manual). Nothing auto-sets it,
 *   because a pause is always a decision somebody made, and it suppresses auto-stall
 *   entirely so deliberate pauses do not pollute the watchlist.
 *
 *   A manual flag outranks the detector. ActivityRecorder only auto-clears a stall it
 *   raised itself (health_source = auto), so an admin who deliberately marked
 *   something Stalled is not undone by a stray comment.
 */
class HealthService
{
    public function __construct(private ActivityRecorder $activity) {}

    public function set(Project $project, ProjectHealth $health, ?string $reason, User $actor): bool
    {
        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        if ($project->health === $health && $project->health_reason === $reason) {
            return false;
        }

        return DB::transaction(function () use ($project, $health, $reason, $actor) {
            $from = $project->health;

            $project->forceFill([
                'health' => $health,
                // Always manual: this method only exists to record a human decision,
                // and marking it 'auto' would let the detector overwrite it tonight.
                'health_source' => HealthSource::Manual,
                'health_reason' => $reason,
                'health_set_at' => now(),
                'health_set_by_user_id' => $actor->id,

                // Cleared whenever health leaves 'stalled', so the next genuine stall
                // episode can notify again rather than being suppressed by the last one.
                'stall_notified_at' => $health === ProjectHealth::Stalled
                    ? $project->stall_notified_at
                    : null,
            ])->save();

            // Recorded in the feed but NOT counted as activity. Flagging a project as
            // stalled must not reset the very clock that measures how stalled it is —
            // otherwise the act of reporting the problem erases the evidence for it.
            $this->activity->record($project, 'health_changed', $actor, [
                'from' => $from->value,
                'to' => $health->value,
                'reason' => $reason,
            ], countsAsActivity: false);

            return true;
        });
    }
}
