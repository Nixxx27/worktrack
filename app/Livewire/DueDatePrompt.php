<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\Step;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "This step needs a due date."
 *
 * Opened by Board::moveProject when a card is dragged into a step whose
 * requires_due_date flag is set and the project has no target_date. The move has
 * already been refused and the card has already snapped back by the time this
 * appears, so the dialog is not confirming a move — it is collecting the one missing
 * fact and then asking the board to try again.
 *
 * SEPARATE FROM ProjectModal on purpose. That form edits eleven fields and requires
 * an owner, a start date and a title; reusing it here would demand a pile of
 * unrelated input from someone who dragged a card two inches, and any field it
 * validates that the project happens to be missing would block the move for a reason
 * that has nothing to do with the gate. One field, one decision.
 *
 * The move parameters are held as state because the dialog outlives the request that
 * refused the drop: without them, saving the date would have nowhere to send the
 * card back to.
 */
class DueDatePrompt extends Component
{
    public bool $open = false;

    /** public_id of the project being gated. */
    public ?string $projectId = null;

    /** The step it was dropped into, and the card it was dropped after. */
    public ?int $stepId = null;

    public ?int $afterProjectId = null;

    public string $dueDate = '';

    /**
     * Named openFor(), not open(), because $open right above it is a public property.
     * Livewire's $wire proxy resolves state before actions, so the two names sharing
     * one identifier makes $wire.open(...) a string in the browser — harmless while
     * this is only ever reached through its #[On] listener, and a live bug the moment
     * anything calls it from a click. Same shape and same fix as ProjectDrawer.
     */
    #[On('due-date-prompt:open')]
    public function openFor(string $project, int $step, ?int $afterProject = null): void
    {
        $this->resetValidation();

        $this->projectId = $project;
        $this->stepId = $step;
        $this->afterProjectId = $afterProject;

        // Blank, NOT prefilled with a plausible date.
        //
        // ProjectModal prefills a fortnight out when CREATING, where the cost of a
        // guess is low and the friction of an empty required field is high. Here the
        // whole point of the gate is that somebody states a commitment, so offering a
        // default that can be accepted with one click would defeat it exactly: the
        // board would fill with dates nobody agreed to, and every lateness figure
        // would then be computed against them.
        $this->dueDate = '';

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetValidation();
    }

    #[Computed]
    public function project(): ?Project
    {
        // Scoped: a public_id from a tracker this user cannot see does not resolve, so
        // a crafted payload gets a closed dialog rather than a project's name.
        return $this->projectId
            ? Project::where('public_id', $this->projectId)->first()
            : null;
    }

    #[Computed]
    public function step(): ?Step
    {
        $project = $this->project;

        return $project && $this->stepId
            ? Step::whereKey($this->stepId)->where('tracker_id', $project->tracker_id)->first()
            : null;
    }

    public function save(ProjectService $projects): void
    {
        $project = $this->project;
        $step = $this->step;

        // Not an error state worth explaining: the card was archived or the column
        // removed while the dialog sat open. Closing and letting the board re-render
        // shows the truth.
        if ($project === null || $step === null) {
            $this->close();
            $this->dispatch('board:refresh');

            return;
        }

        // The same ability the drop needed. Checked again because a dialog that is
        // already open is not evidence of anything — the policy is the gate, not the
        // fact that the UI got this far.
        Gate::authorize('move', $project);

        $data = $this->validate([
            // No `after:today`: a due date that has already passed is a real thing to
            // record — work is often already late by the time anyone states when it
            // was due, and refusing that would push people to invent a future date
            // instead, which is worse than an honest overdue one.
            'dueDate' => ['required', 'date'],
        ], attributes: ['dueDate' => 'due date']);

        // Through the service, never a direct forceFill: update() re-asserts date
        // ordering against start_date and writes the 'field_edit' activity row that
        // makes this read as "Alex set the due date" in the feed. That record is the
        // control that stands in for a permission lock here, so bypassing it would
        // quietly remove the accountability this gate exists to create.
        $projects->update($project, ['target_date' => $data['dueDate']], auth()->user());

        $this->open = false;
        unset($this->project, $this->step);

        // The board re-runs the drop it refused. The gate falls through this time
        // because the date now exists.
        $this->dispatch(
            'due-date-prompt:saved',
            project: $this->projectId,
            step: $this->stepId,
            afterProject: $this->afterProjectId,
        );
    }

    public function render()
    {
        return view('livewire.due-date-prompt');
    }
}
