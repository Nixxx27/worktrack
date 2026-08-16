<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Watch / unwatch a project (FR-4.2, FR-7.2).
 *
 * Watching is how someone who is neither the owner nor an assignee stays in the
 * notification loop — the manager keeping an eye on one job, the person who raised the
 * ticket. project_watchers carries a composite FK to tracker_members with ON DELETE
 * CASCADE, so losing tracker membership silently and correctly stops the emails too.
 *
 * Deliberately writes NO activity row. Watching is a private preference about your own
 * inbox, not a change to the work, and publishing "Joy started watching this" in a feed
 * everyone reads would make people think twice about doing it.
 */
class WatcherService
{
    public function watch(Project $project, User $user): bool
    {
        if ($this->isWatching($project, $user)) {
            return false;
        }

        DB::table('project_watchers')->insert([
            'tracker_id' => $project->tracker_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'watched_at' => now(),
        ]);

        return true;
    }

    public function unwatch(Project $project, User $user): bool
    {
        return DB::table('project_watchers')
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->delete() > 0;
    }

    public function toggle(Project $project, User $user): bool
    {
        return $this->isWatching($project, $user)
            ? ! $this->unwatch($project, $user)
            : $this->watch($project, $user);
    }

    public function isWatching(Project $project, User $user): bool
    {
        return DB::table('project_watchers')
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
