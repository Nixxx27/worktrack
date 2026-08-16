<?php

namespace App\Services\Trackers;

use App\Enums\StepType;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step configuration — FR-3.1, the create half.
 *
 * Steps are owned per tracker (FR-2.2), so everything here is scoped to one tracker
 * and nothing reads or writes across the boundary.
 */
class StepService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Add a column to a tracker's workflow.
     *
     * Placement matters more than it looks. Reorder is not built yet, so a step that
     * could only ever be appended would land after the terminal column — the one
     * position almost nobody wants — with no way to fix it from the UI. Hence
     * $afterStepId: the caller says which existing step it follows, and null means
     * first.
     *
     * Live steps are renumbered 0..n-1 inside the transaction rather than shifting
     * from the insertion point. Positions carry no meaning beyond their order, there
     * is deliberately no unique index on (tracker_id, position) — see the steps
     * migration — and renumbering leaves no gaps for a later insert to trip over.
     * Archived steps keep their old positions and are left out of the numbering:
     * nothing orders them, and rewriting them would churn rows for no reader.
     */
    public function create(Tracker $tracker, array $attributes, User $actor, ?int $afterStepId = null): Step
    {
        if ($tracker->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot add a step to an archived tracker.');
        }

        return DB::transaction(function () use ($tracker, $attributes, $actor, $afterStepId) {
            // lockForUpdate because two admins adding a step at the same moment would
            // otherwise compute the same insertion index from the same snapshot and
            // produce an order neither of them chose.
            $live = Step::where('tracker_id', $tracker->id)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->lockForUpdate()
                ->get();

            $step = Step::create([
                'tracker_id' => $tracker->id,
                'name' => $attributes['name'],
                'type' => $attributes['type'] instanceof StepType
                    ? $attributes['type']
                    : StepType::from($attributes['type']),
                'wip_limit' => $attributes['wip_limit'] ?? null,
                // FR-4.1 gate. Off unless asked for: a new column should not start
                // blocking drops because of a default nobody chose.
                'requires_due_date' => (bool) ($attributes['requires_due_date'] ?? false),
                'description' => $attributes['description'] ?? null,
                // Provisional: the renumbering below is what actually decides it.
                'position' => $live->count(),
            ]);

            $ordered = $this->insert($live, $step, $afterStepId);

            foreach ($ordered as $index => $ordinal) {
                if ((int) $ordinal->position !== $index) {
                    $ordinal->forceFill(['position' => $index])->save();
                }
            }

            $this->audit->log('tracker.step_created', $actor->id, [
                'tracker_id' => $tracker->id,
                'step_id' => $step->id,
                'name' => $step->name,
                'type' => $step->type->value,
                'position' => $step->position,
                'requires_due_date' => $step->requires_due_date,
            ], null, $tracker->id);

            return $step;
        });
    }

    /**
     * FR-3.1 — reorder, one place at a time.
     *
     * Swaps $step with its live neighbour and renumbers, returning false at the ends
     * rather than throwing: "move the first step up" is a no-op, not an error.
     *
     * Worth knowing before calling: position is not cosmetic. ProjectService::create
     * puts every new project in the tracker's FIRST live step by position, so moving
     * a different column to the front changes where new work lands. Step TYPE still
     * drives all metrics (FR-3.7), so reordering cannot corrupt reporting the way
     * retyping can.
     */
    public function move(Step $step, string $direction, User $actor): bool
    {
        return DB::transaction(function () use ($step, $direction, $actor) {
            $live = Step::where('tracker_id', $step->tracker_id)
                ->whereNull('archived_at')
                ->orderBy('position')
                ->lockForUpdate()
                ->get()
                ->values();

            $from = $live->search(fn (Step $s) => $s->id === $step->id);

            if ($from === false) {
                return false;   // archived out from under the form
            }

            $to = $direction === 'up' ? $from - 1 : $from + 1;

            if ($to < 0 || $to >= $live->count()) {
                return false;   // already at the end it was asked to move toward
            }

            $ordered = $live->all();
            [$ordered[$from], $ordered[$to]] = [$ordered[$to], $ordered[$from]];

            foreach ($ordered as $index => $ordinal) {
                if ((int) $ordinal->position !== $index) {
                    $ordinal->forceFill(['position' => $index])->save();
                }
            }

            $this->audit->log('tracker.step_moved', $actor->id, [
                'tracker_id' => $step->tracker_id,
                'step_id' => $step->id,
                'name' => $step->name,
                'from_position' => $from,
                'to_position' => $to,
            ], null, $step->tracker_id);

            return true;
        });
    }

    /**
     * FR-3.1 — rename a step.
     *
     * Safe in a way retyping is not. Metrics group by step TYPE (FR-3.7) and movement
     * rows freeze to_step_type at move time, so nothing numeric moves when a name
     * changes. Movement history does resolve names live through to_step_id, so past
     * rows relabel themselves — which is the correct reading: it is the same column
     * under a new name, not a different one.
     *
     * Returns false when the name is unchanged, so an admin tabbing out of the field
     * without editing it does not write an audit row claiming a rename happened.
     */
    public function rename(Step $step, string $name, User $actor): bool
    {
        if ($step->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot rename an archived step.');
        }

        if ($step->tracker->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot rename a step in an archived tracker.');
        }

        $name = trim($name);
        $was = $step->name;

        if ($name === $was) {
            return false;
        }

        // No transaction and no lock: this is a single-row update of a field nothing
        // else derives from. uk_steps_name_active is the backstop if two admins race
        // to the same name — the loser gets a database error rather than a duplicate,
        // which is why the controller validates uniqueness first.
        $step->forceFill(['name' => $name])->save();

        $this->audit->log('tracker.step_renamed', $actor->id, [
            'tracker_id' => $step->tracker_id,
            'step_id' => $step->id,
            'from' => $was,
            'to' => $name,
        ], null, $step->tracker_id);

        return true;
    }

    /**
     * FR-3.1 — change what a column MEANS.
     *
     * The last step edit to arrive, and the only one that touches metrics. Two things
     * have to be true at once, and they pull in opposite directions.
     *
     * HISTORY IS FROZEN, AND STAYS FROZEN. Every movement row carries the
     * `to_step_type` it was written with, and a project's `first_active_at`,
     * `first_terminal_at`, `cycle_time_seconds` and `skipped_active` were all computed
     * under the typing in force when it moved. None of that is rewritten here. A
     * project that crossed this column while it was `intake` keeps the cycle time it
     * was measured with — recomputing would silently restate every figure anyone has
     * already read, and a number that changes because somebody edited a dropdown is
     * not a measurement.
     *
     * THE PRESENT IS NOT HISTORY. `projects.current_step_type` is a MIRROR of the type
     * of the step a card is sitting in right now, and four readers treat it as
     * authoritative: the stall threshold (intake is given more patience), the nightly
     * stall sweep, the tracker-archive confirmation count, and in-flight metrics.
     * Leaving it stale would not be conservatism, it would be a false statement about
     * where work is — so every project in this column is re-stamped in the same
     * transaction, archived ones included. Archived-ness is orthogonal: the invariant
     * is that the mirror matches the step, and a project restored next month must not
     * come back wrong.
     *
     * DELIBERATELY NOT BACKFILLED: a card sitting in a column being promoted to
     * `active` does not get a `first_active_at`. Stamping now() would invent a moment
     * work started; backdating to when the card arrived would claim knowledge the
     * system never had, since that time was recorded as waiting. Left null, such a card
     * dragged straight to a terminal step is marked `skipped_active` and drops out of
     * cycle time — which the dashboard already counts and says out loud (M-D6). A
     * visible gap beats a confident wrong number.
     *
     * Returns false when the type is unchanged, so re-submitting the form does not
     * write an audit row claiming a change nobody made.
     */
    public function retype(Step $step, StepType $type, User $actor): bool
    {
        if ($step->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot retype an archived step.');
        }

        if ($step->tracker->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot retype a step in an archived tracker.');
        }

        $was = $step->type;

        if ($was === $type) {
            return false;
        }

        // FR-3.3, the same invariant that blocks archiving the last active or terminal
        // step. Reachable from the UI — an admin retyping the only Done column — so the
        // controller checks it first and turns it into a field error. This is the
        // backstop for every other caller.
        if ($this->isLastOfType($step)) {
            throw new \InvalidArgumentException(
                "Cannot retype \"{$step->name}\": it is the only {$was->value} step in this tracker."
            );
        }

        return DB::transaction(function () use ($step, $type, $was, $actor) {
            $step->forceFill(['type' => $type])->save();

            // Scoped like every other read in this application: an actor who may
            // configure this tracker's steps can see this tracker's projects, so the
            // visibility scope narrows nothing here — it is simply not bypassed.
            $restamped = Project::where('step_id', $step->id)
                ->update(['current_step_type' => $type]);

            $this->audit->log('tracker.step_retyped', $actor->id, [
                'tracker_id' => $step->tracker_id,
                'step_id' => $step->id,
                'name' => $step->name,
                'from' => $was->value,
                'to' => $type->value,
                // Recorded because it is the blast radius: this is how many cards had
                // their stall threshold and in-flight classification change underneath
                // them, without anyone moving anything.
                'projects_restamped' => $restamped,
            ], null, $step->tracker_id);

            return true;
        });
    }

    /**
     * FR-3.3 — is this the last live step holding its type up?
     *
     * Only `active` and `terminal` are protected. A tracker with no `intake` column is
     * unusual but coherent — work simply starts in progress — whereas losing the last
     * `active` step makes cycle time uncomputable, and losing the last `terminal` one
     * means nothing can ever be finished.
     *
     * Public because the controller asks the same question to produce a field error
     * rather than an exception, and two spellings of one invariant is one too many.
     */
    public function isLastOfType(Step $step): bool
    {
        if (! in_array($step->type, [StepType::Active, StepType::Terminal], true)) {
            return false;
        }

        return Step::where('tracker_id', $step->tracker_id)
            ->whereNull('archived_at')
            ->where('type', $step->type)
            ->whereKeyNot($step->id)
            ->doesntExist();
    }

    /**
     * Turn the due-date gate on or off for one step.
     *
     * The flag is a policy about FUTURE drops, so flipping it on does not — and must
     * not — reach back and demand a date from the projects already sitting in the
     * column. Those got there under the old rule; retro-flagging them would put a
     * blocking dialog in front of a card that has not moved, which is a worse answer
     * than an old card with no due date.
     *
     * Audited rather than logged as project activity: this is a tracker
     * configuration change by an admin, not something that happened to a project.
     *
     * Returns false when the value is unchanged, so re-submitting the form does not
     * write an audit row claiming a change nobody made.
     */
    public function setRequiresDueDate(Step $step, bool $requires, User $actor): bool
    {
        if ($step->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot configure an archived step.');
        }

        if ($step->tracker->archived_at !== null) {
            throw new \InvalidArgumentException('Cannot configure a step in an archived tracker.');
        }

        if ($step->requires_due_date === $requires) {
            return false;
        }

        $step->forceFill(['requires_due_date' => $requires])->save();

        $this->audit->log('tracker.step_gate_changed', $actor->id, [
            'tracker_id' => $step->tracker_id,
            'step_id' => $step->id,
            'name' => $step->name,
            'gate' => 'requires_due_date',
            'to' => $requires,
        ], null, $step->tracker_id);

        return true;
    }

    /**
     * Place the new step after $afterStepId, or first when that is null.
     *
     * An unknown id appends rather than throwing — it means the step was archived
     * between rendering the form and submitting it, and refusing the whole creation
     * over a stale dropdown would be a worse answer than putting the column at the end.
     *
     * @param  Collection<int, Step>  $live
     * @return list<Step>
     */
    private function insert($live, Step $step, ?int $afterStepId): array
    {
        $ordered = $live->values()->all();

        if ($afterStepId === null) {
            return [$step, ...$ordered];
        }

        $index = null;

        foreach ($ordered as $i => $existing) {
            if ($existing->id === $afterStepId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return [...$ordered, $step];
        }

        array_splice($ordered, $index + 1, 0, [$step]);

        return $ordered;
    }
}
