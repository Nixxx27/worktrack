<?php

namespace App\Models;

use App\Authorization\Scopes\ProjectPrivacyScope;
use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Enums\StepType;
use App\Models\Concerns\BelongsToTracker;
use Database\Factories\ProjectStepMovementFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * THE source of truth for every metric (FR-4.8, FR-8.7).
 *
 * Append-only from the application's point of view: rows are inserted by the
 * movement recorder and the ONLY field ever written afterwards is exited_at, once,
 * when the project leaves the step. Nothing else may update a row, and nothing may
 * delete one — this table cannot be reconstructed from anything else in the system.
 *
 * duration_seconds and open_project_id are database-generated. They are read-only
 * here by design: writing them from PHP would reintroduce exactly the drift the
 * generated columns exist to make impossible.
 */
#[ScopedBy([TrackerVisibilityScope::class, ProjectPrivacyScope::class])]
class ProjectStepMovement extends Model
{
    /** @use HasFactory<ProjectStepMovementFactory> */
    use BelongsToTracker, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_step_type' => StepType::class,
            'to_step_type' => StepType::class,
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function toStep(): BelongsTo
    {
        return $this->belongsTo(Step::class, 'to_step_id');
    }

    /**
     * Nullable — the first movement of every project has no origin (M-D22).
     *
     * Note that history resolves step NAMES live through these relations while the
     * metric families come from the frozen from_step_type / to_step_type columns. That
     * split is deliberate: renaming a column relabels its past rows, which reads
     * correctly as "the same column under a new name", but cannot move a project
     * between metric families retroactively.
     */
    public function fromStep(): BelongsTo
    {
        return $this->belongsTo(Step::class, 'from_step_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by_user_id');
    }
}
