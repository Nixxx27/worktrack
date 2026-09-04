<?php

namespace App\Services\Trackers;

use App\Enums\StepType;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\TrackerMember;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Support\Facades\DB;

class TrackerService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * FR-2.5 — a new tracker is immediately usable.
     *
     * Seeding default steps is not a convenience: a tracker with no steps has nowhere
     * to put a card, so an unseeded tracker is a dead end that looks like a bug. The
     * defaults satisfy FR-3.3's invariant (at least one active and one terminal step)
     * from the moment it exists.
     */
    public function create(array $attributes, User $actor): Tracker
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $tracker = Tracker::create([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'default_department_id' => $attributes['default_department_id'] ?? null,
                'stall_threshold_days' => $attributes['stall_threshold_days'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            foreach ([
                ['Backlog', StepType::Intake],
                ['New', StepType::Intake],
                ['In Progress', StepType::Active],
                ['Done', StepType::Terminal],
            ] as $position => [$name, $type]) {
                Step::create([
                    'tracker_id' => $tracker->id,
                    'name' => $name,
                    'type' => $type,
                    'position' => $position,
                ]);
            }

            // The creating admin becomes a member. Without this the admin who just made
            // a tracker would be relying on the admin-sees-everything bypass to use it,
            // which hides membership bugs during development.
            $this->addMember($tracker, $actor, $actor);

            $this->audit->log('tracker.created', $actor->id, [
                'tracker_id' => $tracker->id,
                'name' => $tracker->name,
            ], null, $tracker->id);

            return $tracker;
        });
    }

    /** D10 — members are chosen from already-approved users; there is no invite-by-email side door. */
    public function addMember(Tracker $tracker, User $user, User $actor): void
    {
        if (! $user->isActive()) {
            throw new \InvalidArgumentException('Only approved users can be added to a tracker.');
        }

        TrackerMember::firstOrCreate(
            ['tracker_id' => $tracker->id, 'user_id' => $user->id],
            ['added_by_user_id' => $actor->id, 'added_at' => now()],
        );

        $this->audit->log('tracker.member_added', $actor->id, [
            'tracker_id' => $tracker->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ], null, $tracker->id);
    }

    /**
     * Remove a member. FR-2.4: immediate, and history is preserved.
     *
     * ═══════════════════════════════════════════════════════════════════════════════
     * This method carries an obligation created by a schema decision.
     *
     * project_assignees and project_watchers cascade automatically — those rows ARE
     * the assignment, so deleting them is correct.
     *
     * tasks.assignee_user_id does NOT cascade, deliberately: a composite FK there
     * hard-deleted the task ROW when a member was removed (VERIFICATION.md
     * data-model-1, reproduced live). MySQL cannot express ON DELETE SET NULL for that
     * shape, so the application must do it — right here, in the same transaction.
     *
     * If this UPDATE is ever removed, tasks silently keep pointing at someone who can
     * no longer see the tracker, and FR-8.4's per-person workload counts a non-member.
     * ═══════════════════════════════════════════════════════════════════════════════
     */
    public function removeMember(Tracker $tracker, User $user, User $actor): void
    {
        DB::transaction(function () use ($tracker, $user, $actor) {
            // Record what is about to be detached BEFORE the cascade removes the
            // evidence, so the audit trail explains why work became unassigned.
            $assignedProjects = DB::table('project_assignees')
                ->where('tracker_id', $tracker->id)
                ->where('user_id', $user->id)
                ->pluck('project_id');

            $taskIds = DB::table('tasks')
                ->where('tracker_id', $tracker->id)
                ->where('assignee_user_id', $user->id)
                ->whereNull('deleted_at')
                ->pluck('id');

            // The obligation described above.
            DB::table('tasks')
                ->where('tracker_id', $tracker->id)
                ->where('assignee_user_id', $user->id)
                ->update(['assignee_user_id' => null, 'updated_at' => now()]);

            // Ownership is a plain FK for the same MySQL reason, so it needs the same
            // explicit handling. A project left ownerless is visible on the board rather
            // than quietly orphaned.
            // NOTE (FR-4.11): both reads below run through the visibility scope, so a
            // private card owned by the person being removed is left alone rather than
            // orphaned. That is the better of two bad options — clearing its owner would
            // hide it from every user including its owner, permanently and with no route
            // back through any screen — but it does leave a card owned by a non-member,
            // reachable only from the database. Recorded as a known limitation in
            // REQUIREMENTS FR-4.11 rather than silently relied upon.
            $ownedProjects = Project::where('tracker_id', $tracker->id)
                ->where('owner_user_id', $user->id)
                ->pluck('id');

            Project::where('tracker_id', $tracker->id)
                ->where('owner_user_id', $user->id)
                ->update(['owner_user_id' => null]);

            // Cascades project_assignees and project_watchers via composite FK.
            TrackerMember::where('tracker_id', $tracker->id)->where('user_id', $user->id)->delete();

            $this->audit->log('tracker.member_removed', $actor->id, [
                'tracker_id' => $tracker->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'unassigned_projects' => $assignedProjects->all(),
                'unassigned_tasks' => $taskIds->all(),
                'orphaned_projects' => $ownedProjects->all(),
            ], null, $tracker->id);
        });
    }

    /**
     * FR-2.8 — archiving requires knowing how much live work it hides.
     * Does NOT cascade to projects: their history must stay intact and reportable.
     */
    public function archive(Tracker $tracker, User $actor): int
    {
        return DB::transaction(function () use ($tracker, $actor) {
            // Privacy-blind (FR-4.11): the whole requirement is "archiving requires
            // knowing how much live work it hides", and a figure that silently omitted
            // members' private cards would be the wrong number for the one decision it
            // exists to inform. It is an aggregate — a count of live work, not a list —
            // and this file already reads projects raw for the neighbouring writes.
            $liveProjects = (int) DB::table('projects')
                ->where('tracker_id', $tracker->id)
                ->whereNull('archived_at')
                ->where('current_step_type', '!=', StepType::Terminal->value)
                ->count();

            $tracker->forceFill([
                'archived_at' => now(),
                'archived_by_user_id' => $actor->id,
            ])->save();

            $this->audit->log('tracker.archived', $actor->id, [
                'tracker_id' => $tracker->id,
                'live_projects_hidden' => $liveProjects,
            ], null, $tracker->id);

            return $liveProjects;
        });
    }
}
