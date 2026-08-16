<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Step;
use App\Models\Tag;
use App\Models\Tracker;
use App\Models\TrackerMember;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    /**
     * The fields a project form may edit, and the label the activity log shows for
     * each. Anything not listed here is not editable through this seam — notably the
     * denormalized read-model columns, which only the movement and activity recorders
     * may write.
     */
    private const EDITABLE = [
        'name' => 'the title',
        'description' => 'the description',
        'start_date' => 'the start date',
        'target_date' => 'the due date',
        'priority' => 'the priority',
    ];

    public function __construct(
        private MovementRecorder $movements,
        private ActivityRecorder $activity,
        private TagService $tags,
        private AuditLogger $audit,
    ) {}

    /**
     * Create a project, with everything the form collected, in one transaction.
     *
     * FR-4.1 makes `name` the only field this layer requires, and that stays true —
     * the seeder, the tests and any future import all create projects with a name
     * alone. The start and due dates the board form now marks required are a UI-level
     * decision enforced in ProjectModal, not a domain invariant: making them
     * structurally mandatory would break every one of those callers and, more to the
     * point, would mean a project that genuinely has no agreed due date cannot be
     * recorded at all — and an unrecorded project is the one failure mode this
     * product cannot tolerate (NFR-U1: friction is what makes dashboards fiction).
     *
     * @param  array{
     *     name: string, description?: ?string, start_date?: ?string, target_date?: ?string,
     *     priority?: string, department_id?: ?int, owner_user_id?: ?int,
     *     assignees?: list<int>, tags?: list<string>
     * }  $attributes
     */
    public function create(Tracker $tracker, array $attributes, User $actor): Project
    {
        $this->assertDateOrder($attributes['start_date'] ?? null, $attributes['target_date'] ?? null);

        return DB::transaction(function () use ($tracker, $attributes, $actor) {
            // Lands in the tracker's first live step by position — its intake column.
            $step = Step::where('tracker_id', $tracker->id)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->firstOrFail();

            // Defaulting to the creator keeps FR-4.1's one-field path working: a
            // project always has someone accountable even when the form is skipped.
            $ownerId = array_key_exists('owner_user_id', $attributes)
                ? $attributes['owner_user_id']
                : $actor->id;

            $this->assertMembers($tracker->id, array_filter([$ownerId]), 'owner');

            $project = Project::create([
                'tracker_id' => $tracker->id,
                'step_id' => $step->id,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,

                // Inherited from the tracker, editable per project (FR-4.1) — which is
                // exactly why the movement row snapshots it.
                'department_id' => $attributes['department_id'] ?? $tracker->default_department_id,

                'owner_user_id' => $ownerId,
                'priority' => $attributes['priority'] ?? 'normal',
                'start_date' => $attributes['start_date'] ?? null,
                'target_date' => $attributes['target_date'] ?? null,
                'board_position' => $this->nextPosition($tracker->id, $step->id),

                // Set here as well as by the recorder so the row is never momentarily
                // NULL — the column is NOT NULL precisely because a NULL activity clock
                // makes a project invisible to stall detection forever.
                'last_activity_at' => now(),
                'current_step_entered_at' => now(),
                'current_step_type' => $step->type,
            ]);

            // We were handed the tracker, so hand it to the model rather than letting
            // applyTags lazy-load it back through the visibility scope — one query
            // saved, and no dependence on the creator's own visibility to tag a
            // project they are in the middle of creating.
            $project->setRelation('tracker', $tracker);

            // Both happen BEFORE recordCreation, so the single 'created' activity row
            // can carry them. Writing separate assignees_changed / tags_changed rows
            // for a project that did not exist a moment ago would read as three
            // edits to something with no history.
            $assignees = $this->applyAssignees($project, $attributes['assignees'] ?? [], $actor);
            $tags = $this->applyTags($project, $attributes['tags'] ?? [], $actor);

            $this->movements->recordCreation($project, $step, $actor, [
                'owner' => $ownerId ? User::find($ownerId)?->name : null,
                'assignees' => $assignees->pluck('name')->all(),
                'tags' => $tags->pluck('name')->all(),
                'start_date' => $attributes['start_date'] ?? null,
                'target_date' => $attributes['target_date'] ?? null,
            ]);

            return $project->refresh();
        });
    }

    /**
     * Edit a project, writing one attributed activity row per KIND of change.
     *
     * The rows are split by kind — fields, owner, assignees, tags — rather than
     * merged into one "updated" row, because the log is the control that replaces the
     * permission lock now that any member may edit any card. "Alex edited Firewall
     * upgrade" is not reviewable; "Alex changed the owner from Joy to themselves" is.
     *
     * A call that changes nothing writes nothing and returns false, so tabbing
     * through a form without editing it cannot manufacture activity — which would
     * also silently reset the stall clock on a project nobody actually touched.
     *
     * @param  array<string, mixed>  $attributes  any subset of self::EDITABLE, plus
     *                                            owner_user_id, assignees, tags
     */
    public function update(Project $project, array $attributes, User $actor): bool
    {
        $this->assertDateOrder(
            array_key_exists('start_date', $attributes) ? $attributes['start_date'] : $project->start_date,
            array_key_exists('target_date', $attributes) ? $attributes['target_date'] : $project->target_date,
        );

        return DB::transaction(function () use ($project, $attributes, $actor) {
            $changed = false;

            if ($fields = $this->diffFields($project, $attributes)) {
                $project->forceFill(
                    collect($fields)->mapWithKeys(fn (array $c) => [$c['column'] => $c['to']])->all()
                )->save();

                // The column name is for the write, not the reader: the log says
                // "edited the due date", not "edited target_date".
                $this->activity->record($project, 'field_edit', $actor, [
                    'fields' => collect($fields)
                        ->map(fn (array $c) => ['from' => $c['from'], 'to' => $c['to']])
                        ->all(),
                ]);

                $changed = true;
            }

            if (array_key_exists('owner_user_id', $attributes)) {
                $changed = $this->changeOwner($project, $attributes['owner_user_id'], $actor) || $changed;
            }

            if (array_key_exists('assignees', $attributes)) {
                $changed = $this->changeAssignees($project, $attributes['assignees'] ?? [], $actor) || $changed;
            }

            if (array_key_exists('tags', $attributes)) {
                $changed = $this->changeTags($project, $attributes['tags'] ?? [], $actor) || $changed;
            }

            return $changed;
        });
    }

    /**
     * FR-4.9 — take a project off the board without destroying it.
     *
     * `ProjectPolicy::archive` has existed since the policies were written and nothing
     * ever called it: the capability was in the matrix, enforced on a request nobody
     * could make. This is the seam that makes it real.
     *
     * Hard deletion is never offered, and that is the whole point of the requirement.
     * Every metric in the system is derived from `project_step_movements`, so deleting a
     * project would silently rewrite completed history — last quarter's throughput would
     * change because someone tidied the board this morning.
     *
     * The open movement interval is deliberately LEFT open. Closing it would fabricate
     * an exit that never happened, and FR-4.8 makes movement history the one thing users
     * never edit; archiving is not a step change. Nothing downstream needs the fiction —
     * `averageSecondsByStepType` reads only closed intervals, and every board, aging and
     * watchlist query already filters on `archived_at`.
     *
     * Authorization is the caller's job, as with every other method here.
     *
     * @return bool false if it was already archived — archiving twice is not an error,
     *              it is a second click on a button whose card had not yet disappeared
     */
    public function archive(Project $project, User $actor): bool
    {
        if ($project->archived_at !== null) {
            return false;
        }

        return DB::transaction(function () use ($project, $actor) {
            $project->forceFill([
                'archived_at' => now(),
                'archived_by_user_id' => $actor->id,
            ])->save();

            // In the feed but NOT counted as activity: archiving is the removal of work,
            // not work. Counting it would refresh the stall clock of the very project
            // being taken off the board.
            $this->activity->record($project, 'archived', $actor, [
                'step' => $project->step?->name,
            ], countsAsActivity: false);

            // FR-9.1 names project archival in the ADMIN audit log, and it is the only
            // project operation here that writes one. Create and move are visible on the
            // board and reversible by dragging; archiving removes work from every report
            // at once, which is exactly the class of act the audit log exists for.
            $this->audit->log('project.archived', $actor->id, [
                'project_id' => $project->id,
                'project_public_id' => $project->public_id,
                'name' => $project->name,
            ], null, $project->tracker_id);

            return true;
        });
    }

    /**
     * Put an archived project back on the board.
     *
     * Not in FR-4.9, and added deliberately: an archive with no way back is a trap, and
     * the recovery without it is a hand-written UPDATE against production by the one
     * person who knows the table. The same capability governs both directions — anyone
     * who may remove work from the reports may put it back.
     */
    public function restore(Project $project, User $actor): bool
    {
        if ($project->archived_at === null) {
            return false;
        }

        return DB::transaction(function () use ($project, $actor) {
            $project->forceFill([
                'archived_at' => null,
                'archived_by_user_id' => null,
            ])->save();

            // This one DOES count as activity, and must. Stall detection skips archived
            // projects, so a card restored after two months carries a two-month-old
            // last_activity_at: without this it is flagged Stalled by the next sweep and
            // the person who restored it is emailed about their own click.
            $this->activity->record($project, 'restored', $actor);

            $this->audit->log('project.restored', $actor->id, [
                'project_id' => $project->id,
                'project_public_id' => $project->public_id,
                'name' => $project->name,
            ], null, $project->tracker_id);

            return true;
        });
    }

    /**
     * Move a card, and place it within the destination column.
     *
     * Authorization is the caller's job (ProjectPolicy::move) — this is the domain
     * operation, and keeping the check out of here means it cannot be accidentally
     * satisfied by a service call that skips the policy.
     */
    public function move(Project $project, Step $toStep, User $actor, ?float $position = null): Project
    {
        return DB::transaction(function () use ($project, $toStep, $actor, $position) {
            $this->movements->recordMove($project, $toStep, $actor);

            $project->forceFill([
                'board_position' => $position ?? $this->nextPosition($project->tracker_id, $toStep->id),
            ])->save();

            return $project->refresh();
        });
    }

    /**
     * Midpoint insertion (DD-17). Dropping between two cards writes ONE row.
     *
     * Contiguous integers would rewrite every card below the drop point — 250 rows at
     * the 500-card target, inside the request the optimistic UI then has to reconcile.
     * DECIMAL(20,10) rather than float because binary floating point loses the midpoint
     * after roughly 50 successive same-gap insertions and two cards silently collide.
     */
    public function positionBetween(?float $before, ?float $after): float
    {
        if ($before === null && $after === null) {
            return 1000.0;
        }

        if ($before === null) {
            return $after / 2;
        }

        if ($after === null) {
            return $before + 1000;
        }

        return ($before + $after) / 2;
    }

    // ── internals ───────────────────────────────────────────────────────────────

    /**
     * Which editable fields actually differ, as from/to pairs for the log.
     *
     * Dates are compared as Y-m-d strings and everything else as trimmed strings,
     * because the form submits strings and the model holds Carbon instances — a naive
     * !== between the two reports every save as a change to every date field.
     *
     * Keyed by the human label so the log reads without a lookup table; each entry
     * carries its column so the caller can write in one forceFill.
     *
     * @return array<string, array{from: ?string, to: ?string, column: string}>
     */
    private function diffFields(Project $project, array $attributes): array
    {
        $changes = [];

        foreach (self::EDITABLE as $column => $label) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $to = $this->normaliseValue($column, $attributes[$column]);
            $from = $this->normaliseValue($column, $project->getAttribute($column));

            if ($from !== $to) {
                $changes[$label] = ['from' => $from, 'to' => $to, 'column' => $column];
            }
        }

        return $changes;
    }

    private function normaliseValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($field, ['start_date', 'target_date'], true)) {
            return Carbon::parse((string) $value)->toDateString();
        }

        return trim((string) $value);
    }

    private function changeOwner(Project $project, ?int $ownerId, User $actor): bool
    {
        if ((int) $project->owner_user_id === (int) $ownerId || ($project->owner_user_id === null && $ownerId === null)) {
            return false;
        }

        $this->assertMembers($project->tracker_id, array_filter([$ownerId]), 'owner');

        // Captured before the save: after forceFill()->save() the model's original
        // IS the new value, so reading it afterwards logs "changed owner from Joy to
        // Joy" — a row that looks like a bug in the log rather than in this method.
        $fromId = $project->owner_user_id;
        $from = $project->owner?->name;
        $to = $ownerId ? User::find($ownerId)?->name : null;

        $project->forceFill(['owner_user_id' => $ownerId])->save();
        $project->unsetRelation('owner');

        $this->activity->record($project, 'owner_changed', $actor, [
            'from' => $from,
            'to' => $to,
            'from_user_id' => $fromId,
            'to_user_id' => $ownerId,
        ]);

        return true;
    }

    /** @param  list<int>  $userIds */
    private function changeAssignees(Project $project, array $userIds, User $actor): bool
    {
        $before = $project->assignees()->pluck('users.name', 'users.id');

        $result = $this->applyAssignees($project, $userIds, $actor, sync: true);

        $after = $result->pluck('name', 'id');

        $added = $after->diffKeys($before)->values()->all();
        $removed = $before->diffKeys($after)->values()->all();

        if (! $added && ! $removed) {
            return false;
        }

        $this->activity->record($project, 'assignees_changed', $actor, [
            'added' => $added,
            'removed' => $removed,
        ]);

        return true;
    }

    /** @param  list<string>  $names */
    private function changeTags(Project $project, array $names, User $actor): bool
    {
        $before = $project->tags()->pluck('tags.name', 'tags.id');

        $result = $this->applyTags($project, $names, $actor, sync: true);

        $after = $result->pluck('name', 'id');

        $added = $after->diffKeys($before)->values()->all();
        $removed = $before->diffKeys($after)->values()->all();

        if (! $added && ! $removed) {
            return false;
        }

        $this->activity->record($project, 'tags_changed', $actor, [
            'added' => $added,
            'removed' => $removed,
        ]);

        return true;
    }

    /**
     * Attach assignees, refusing anyone who is not a member of the tracker.
     *
     * FR-4.3 is already a database invariant here — project_assignees carries a
     * composite FK to tracker_members — so this check exists to turn errno 1452 into
     * a sentence a person can act on, not to be the enforcement.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function applyAssignees(Project $project, array $userIds, User $actor, bool $sync = false)
    {
        $userIds = array_values(array_unique(array_map('intval', array_filter($userIds))));

        $this->assertMembers($project->tracker_id, $userIds, 'assignee');

        $payload = array_fill_keys($userIds, [
            'tracker_id' => $project->tracker_id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);

        $sync ? $project->assignees()->sync($payload) : $project->assignees()->attach($payload);

        $project->unsetRelation('assignees');

        return $project->assignees()->get();
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Tag>
     */
    private function applyTags(Project $project, array $names, User $actor, bool $sync = false)
    {
        $tags = $this->tags->resolve($project->tracker, $names, $actor);

        $payload = $tags->mapWithKeys(fn (Tag $tag) => [$tag->id => [
            'tracker_id' => $project->tracker_id,
            'tagged_at' => now(),
            'tagged_by_user_id' => $actor->id,
        ]])->all();

        $sync ? $project->tags()->sync($payload) : $project->tags()->attach($payload);

        $project->unsetRelation('tags');

        return $project->tags()->get();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertMembers(int $trackerId, array $userIds, string $role): void
    {
        if ($userIds === []) {
            return;
        }

        $members = TrackerMember::where('tracker_id', $trackerId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $strangers = array_diff(array_map('intval', $userIds), $members);

        if ($strangers !== []) {
            throw new \InvalidArgumentException(
                "Cannot set a {$role} who is not a member of this tracker — you cannot "
                .'assign work to someone who cannot see it (FR-4.3).'
            );
        }
    }

    private function assertDateOrder(mixed $start, mixed $target): void
    {
        if ($start === null || $target === null || $start === '' || $target === '') {
            return;
        }

        if (Carbon::parse((string) $start)->gt(Carbon::parse((string) $target))) {
            throw new \InvalidArgumentException('A project cannot be due before it starts.');
        }
    }

    private function nextPosition(int $trackerId, int $stepId): float
    {
        $max = Project::where('tracker_id', $trackerId)
            ->where('step_id', $stepId)
            ->max('board_position');

        return ((float) $max) + 1000;
    }
}
