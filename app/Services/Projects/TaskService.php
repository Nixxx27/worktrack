<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Task;
use App\Models\TrackerMember;
use App\Models\User;
use App\Services\Notifications\OutboxWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The checklist inside a project (FR-5).
 *
 * THE ONLY WRITE PATH for tasks, and therefore the only writer of
 * projects.tasks_total / tasks_done. Those two columns are what the card front
 * renders as "3/8" (FR-4.4, FR-5.3), and they carry a database CHECK constraint
 * (tasks_done <= tasks_total) that a partial update can violate outright — the
 * migration documents a live incident where a stale counter left a card reading 3/8
 * while seven tasks existed, and the next completion would have failed the check.
 *
 * So the counters are RECOMPUTED from the table on every mutation rather than
 * incremented. It is one indexed COUNT against a handful of rows, and it is
 * self-healing: any drift from an earlier bug corrects itself the next time anybody
 * touches the checklist, instead of persisting until someone notices the maths.
 */
class TaskService
{
    public function __construct(
        private ActivityRecorder $activity,
        private OutboxWriter $outbox,
    ) {}

    /**
     * @param  array{title: string, description?: ?string, assignee_user_id?: ?int, due_date?: ?string}  $attributes
     */
    public function create(Project $project, array $attributes, User $actor): Task
    {
        $assigneeId = $attributes['assignee_user_id'] ?? null;

        $this->assertMember($project->tracker_id, $assigneeId);

        return DB::transaction(function () use ($project, $attributes, $actor, $assigneeId) {
            $task = Task::create([
                'tracker_id' => $project->tracker_id,
                'project_id' => $project->id,
                'title' => trim($attributes['title']),
                'description' => $attributes['description'] ?? null,
                'assignee_user_id' => $assigneeId,
                'due_date' => $attributes['due_date'] ?? null,
                'position' => $this->nextPosition($project),
                'created_by_user_id' => $actor->id,
            ]);

            $this->syncCounters($project);

            // FR-4.7 counts "task added" as activity, so this also resets the stall
            // clock — which is correct: someone breaking work down IS working on it.
            $this->activity->record($project, 'task_created', $actor, [
                'title' => $task->title,
                'assignee' => $assigneeId ? User::find($assigneeId)?->name : null,
            ]);

            if ($assigneeId !== null && $assigneeId !== $actor->id) {
                $this->outbox->queueTaskAssigned($project, $task, $actor);
            }

            return $task;
        });
    }

    /**
     * Tick or untick.
     *
     * completed_at and completed_by are set and cleared together with is_done because
     * the table's CHECK constraint requires exactly that pairing — a bare
     * `is_done = 1` update is rejected by the engine, which is the intended design.
     */
    public function setDone(Task $task, bool $done, User $actor): bool
    {
        if ((bool) $task->is_done === $done) {
            return false;   // already in that state; no row, no stall-clock reset
        }

        return DB::transaction(function () use ($task, $done, $actor) {
            $task->forceFill([
                'is_done' => $done,
                'completed_at' => $done ? now() : null,
                'completed_by_user_id' => $done ? $actor->id : null,
            ])->save();

            $project = $task->project;

            $this->syncCounters($project);

            $this->activity->record($project, $done ? 'task_completed' : 'task_reopened', $actor, [
                'title' => $task->title,
            ]);

            return true;
        });
    }

    /**
     * Edit a task's own fields.
     *
     * @param  array<string, mixed>  $attributes  title, description, assignee_user_id, due_date
     */
    public function update(Task $task, array $attributes, User $actor): bool
    {
        if (array_key_exists('assignee_user_id', $attributes)) {
            $this->assertMember($task->tracker_id, $attributes['assignee_user_id']);
        }

        return DB::transaction(function () use ($task, $attributes, $actor) {
            $changes = [];
            $wasAssignedTo = $task->assignee_user_id;

            foreach (['title' => 'title', 'description' => 'description', 'due_date' => 'due date'] as $column => $label) {
                if (! array_key_exists($column, $attributes)) {
                    continue;
                }

                $to = $this->normalise($column, $attributes[$column]);

                if ($this->normalise($column, $task->getAttribute($column)) !== $to) {
                    $changes[$label] = $to;
                    $task->setAttribute($column, $to);
                }
            }

            $reassigned = array_key_exists('assignee_user_id', $attributes)
                && (int) $attributes['assignee_user_id'] !== (int) $wasAssignedTo;

            if ($reassigned) {
                $task->setAttribute('assignee_user_id', $attributes['assignee_user_id']);
            }

            if (! $changes && ! $reassigned) {
                return false;
            }

            $task->save();

            $this->activity->record($task->project, 'task_updated', $actor, [
                'title' => $task->title,
                'fields' => array_keys($changes),
                'assignee' => $reassigned
                    ? ($task->assignee_user_id ? User::find($task->assignee_user_id)?->name : null)
                    : null,
            ]);

            // FR-5.4 — assigning a task emails the assignee. Only on a genuine
            // reassignment, and never to the person who did it.
            if ($reassigned && $task->assignee_user_id !== null && $task->assignee_user_id !== $actor->id) {
                $this->outbox->queueTaskAssigned($task->project, $task, $actor);
            }

            return true;
        });
    }

    /**
     * Soft-delete. DD-3: exclusion is the reporting-correct default, and a deleted
     * task that vanished from history would make the completion percentages on old
     * cards unreproducible.
     */
    public function delete(Task $task, User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
            $project = $task->project;
            $title = $task->title;

            $task->delete();

            $this->syncCounters($project);

            $this->activity->record($project, 'task_deleted', $actor, ['title' => $title]);
        });
    }

    /**
     * Move a task one place within its project.
     *
     * Renumbered rather than swapped, for the same reason steps are: positions carry
     * no meaning beyond their order, and renumbering leaves no gaps for a later
     * insert to land in the wrong place.
     */
    public function move(Task $task, string $direction, User $actor): bool
    {
        return DB::transaction(function () use ($task, $direction) {
            $ordered = Task::where('project_id', $task->project_id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();

            $from = $ordered->search(fn (Task $t) => $t->id === $task->id);

            if ($from === false) {
                return false;
            }

            $to = $direction === 'up' ? $from - 1 : $from + 1;

            if ($to < 0 || $to >= $ordered->count()) {
                return false;   // already at the end it was asked to move toward
            }

            $list = $ordered->all();
            [$list[$from], $list[$to]] = [$list[$to], $list[$from]];

            foreach ($list as $index => $item) {
                if ((float) $item->position !== (float) ($index * 1000)) {
                    $item->forceFill(['position' => $index * 1000])->save();
                }
            }

            // Deliberately NOT an activity row. Reordering a checklist is tidying, not
            // progress, and counting it would reset the stall clock on a project where
            // nothing actually happened — the precise failure ActivityRecorder exists
            // to prevent.
            return true;
        });
    }

    /**
     * Recompute the card's counters from the tasks table.
     *
     * Both columns are written in one statement so the tasks_done <= tasks_total
     * CHECK can never observe a half-applied pair.
     */
    private function syncCounters(Project $project): void
    {
        $counts = Task::where('project_id', $project->id)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(is_done), 0) AS done')
            ->first();

        $project->forceFill([
            'tasks_total' => (int) $counts->total,
            'tasks_done' => (int) $counts->done,
        ])->save();
    }

    private function nextPosition(Project $project): float
    {
        return ((float) Task::where('project_id', $project->id)->max('position')) + 1000;
    }

    private function normalise(string $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $column === 'title' ? '' : null;
        }

        if ($column === 'due_date') {
            return Carbon::parse((string) $value)->toDateString();
        }

        return trim((string) $value);
    }

    /**
     * FR-5.4 / FR-4.3 — assignees are limited to tracker members.
     *
     * Unlike project_assignees, this is NOT enforced by a composite foreign key, and
     * the reason is documented at length in the tasks migration: the cascading FK that
     * would enforce it hard-deleted the task row when a member was removed, because
     * tasks is an entity table rather than a pivot. So this check IS the enforcement
     * here, not a friendlier wrapper around one.
     */
    private function assertMember(int $trackerId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $isMember = TrackerMember::where('tracker_id', $trackerId)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw new \InvalidArgumentException(
                'A task can only be assigned to a member of this tracker — you cannot '
                .'assign work to someone who cannot see it (FR-5.4).'
            );
        }
    }
}
