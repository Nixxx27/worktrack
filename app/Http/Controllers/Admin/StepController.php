<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StepType;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Services\Trackers\StepService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Step configuration (FR-3.1).
 *
 * Two authorization layers, both required. The route carries the capability gate —
 * may this ROLE ever configure steps — and Gate::authorize below adds the per-tracker
 * one. Neither is redundant: the capability check knows nothing about trackers, and
 * the policy check knows nothing about roles until it asks the matrix.
 */
class StepController
{
    public function store(Request $request, Tracker $tracker, StepService $steps): RedirectResponse
    {
        Gate::authorize('configureSteps', $tracker);

        // Bagged per tracker: the admin screen renders one of these forms per tracker
        // alongside a "New tracker" form that also has a `name` field, and a shared
        // error bag would light up every one of them.
        $data = $request->validateWithBag("step_{$tracker->id}", [
            'name' => [
                'required', 'string', 'max:80',
                // Mirrors uk_steps_name_active: two LIVE steps in one tracker cannot
                // share a name, but an archived "Testing" does not block a new one.
                // Without this the DB would reject the insert as a 500 instead of a
                // field error.
                Rule::unique('steps', 'name')
                    ->where(fn ($q) => $q->where('tracker_id', $tracker->id)->whereNull('archived_at')),
            ],
            'type' => ['required', Rule::enum(StepType::class)],
            'after_step_id' => ['nullable', 'integer'],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:999'],
            // An unchecked checkbox sends nothing at all, so this has to tolerate
            // absence rather than require a value.
            'requires_due_date' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'This tracker already has a live step called that.',
        ], ['after_step_id' => 'position']);

        // Deliberately NO `exists:steps,id` rule, and no lookup here.
        //
        // An unscoped exists check is the existence oracle VERIFICATION.md
        // authorization-3 describes: "invalid step" for an id that is not in the table
        // versus a different outcome for one that is tells an attacker the step
        // structure of trackers they cannot see. Instead the id is passed through and
        // resolved by StepService against THIS tracker's own steps only — an id from
        // anywhere else is indistinguishable from one that never existed, and both
        // simply append.
        //
        // Resolving it here would also destroy a distinction the service relies on:
        // null means "place first", which is not what an unrecognised id should mean.
        $step = $steps->create($tracker, $data, $request->user(), $data['after_step_id'] ?? null);

        return back()->with('status', "Added \"{$step->name}\" to {$tracker->name}.");
    }

    /** FR-3.1 — rename a step in place. */
    public function rename(Request $request, Tracker $tracker, Step $step, StepService $steps): RedirectResponse
    {
        Gate::authorize('configureSteps', $tracker);

        abort_unless($step->tracker_id === $tracker->id, 404);

        // Its own bag, keyed by step: the admin screen renders one of these inputs per
        // step per tracker, and a shared bag would mark every field on the page.
        $data = $request->validateWithBag("step_rename_{$step->id}", [
            'name' => [
                'required', 'string', 'max:80',
                // ignore() so saving a step under its current name is not a collision
                // with itself. Without it, every no-op submit would be a field error.
                Rule::unique('steps', 'name')
                    ->ignore($step->id)
                    ->where(fn ($q) => $q->where('tracker_id', $tracker->id)->whereNull('archived_at')),
            ],
        ], [
            'name.unique' => 'This tracker already has a live step called that.',
        ]);

        $renamed = $steps->rename($step, $data['name'], $request->user());

        // A no-op submit gets no status message. Announcing "renamed Done to Done"
        // would be a lie about what the system did.
        return $renamed
            ? back()->with('status', "Renamed to \"{$step->name}\".")
            : back();
    }

    /**
     * FR-3.1 — change what a column means.
     *
     * Its own endpoint for the same reason rename and move are: the steps list is a row
     * of chips with no save button, so each control has to be the whole interaction.
     *
     * Unlike the others, this one changes how work is MEASURED — see
     * StepService::retype for what is and is not rewritten.
     */
    public function retype(Request $request, Tracker $tracker, Step $step, StepService $steps): RedirectResponse
    {
        Gate::authorize('configureSteps', $tracker);

        abort_unless($step->tracker_id === $tracker->id, 404);

        // Bagged per step, like rename: `type` is also the add-step form's select name,
        // and a shared bag would mark every one of them on the page.
        $data = $request->validateWithBag("step_type_{$step->id}", [
            'type' => [
                'required',
                Rule::enum(StepType::class),
                // FR-3.3, asked here rather than left to the service exception: an admin
                // retyping the only Done column is an ordinary mistake reachable from
                // the UI, and it deserves a sentence rather than a 500. The service
                // still refuses independently — this is the message, not the guard.
                // tryFrom, not from: Laravel keeps running an attribute's remaining rules
                // after one fails, so a tampered value that Rule::enum has already
                // rejected still reaches this closure — and from() would turn a field
                // error into a ValueError.
                function (string $attribute, mixed $value, \Closure $fail) use ($step, $steps) {
                    $to = StepType::tryFrom((string) $value);

                    if ($to !== null && $to !== $step->type && $steps->isLastOfType($step)) {
                        $fail("\"{$step->name}\" is the only {$step->type->value} step in this tracker. "
                            .'Add another one first, or the tracker loses the ability to '
                            .($step->type === StepType::Active ? 'measure cycle time.' : 'finish anything.'));
                    }
                },
            ],
        ], attributes: ['type' => 'step type']);

        $type = StepType::from($data['type']);

        // Counted BEFORE the write, and reported: this is the one step edit that
        // reclassifies cards nobody touched, and an admin who is not told how many is
        // being asked to trust a silent bulk change.
        $affected = Project::where('step_id', $step->id)->count();

        if (! $steps->retype($step, $type, $request->user())) {
            return back();
        }

        $means = match ($type) {
            StepType::Intake => 'time there counts as waiting, not working',
            StepType::Active => 'time there counts toward cycle time',
            StepType::Terminal => 'entering it stops the clock',
        };

        return back()->with('status', "\"{$step->name}\" is now {$type->value}: {$means}."
            .($affected > 0
                ? " {$affected} project(s) in that column were reclassified. Finished work keeps the cycle time it was already measured with."
                : ''));
    }

    /**
     * Toggle the due-date gate on a step.
     *
     * Its own endpoint rather than a field on a bigger step-settings form, because the
     * steps list is a row of chips with no save button — the same reasoning that made
     * rename and move their own tiny posts.
     */
    public function gate(Request $request, Tracker $tracker, Step $step, StepService $steps): RedirectResponse
    {
        Gate::authorize('configureSteps', $tracker);

        abort_unless($step->tracker_id === $tracker->id, 404);

        $data = $request->validate([
            'requires_due_date' => ['required', 'boolean'],
        ]);

        $changed = $steps->setRequiresDueDate($step, (bool) $data['requires_due_date'], $request->user());

        if (! $changed) {
            return back();
        }

        return back()->with('status', $data['requires_due_date']
            ? "Moving a project into \"{$step->name}\" now requires a due date."
            : "\"{$step->name}\" no longer requires a due date.");
    }

    /** FR-3.1 — move one step one place along the workflow. */
    public function move(Request $request, Tracker $tracker, Step $step, StepService $steps): RedirectResponse
    {
        Gate::authorize('configureSteps', $tracker);

        // Binding resolves Step by id across the whole visible set, and an admin sees
        // every tracker — so the tracker this step actually belongs to must be checked
        // rather than assumed from the URL.
        abort_unless($step->tracker_id === $tracker->id, 404);

        $data = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $moved = $steps->move($step, $data['direction'], $request->user());
        $up = $data['direction'] === 'up';

        return back()->with('status', $moved
            ? 'Moved "'.$step->name.'" '.($up ? 'earlier' : 'later').' in the workflow.'
            : '"'.$step->name.'" is already the '.($up ? 'first' : 'last').' step.');
    }
}
