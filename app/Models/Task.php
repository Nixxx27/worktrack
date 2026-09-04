<?php

namespace App\Models;

use App\Authorization\Scopes\ProjectPrivacyScope;
use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Models\Concerns\BelongsToTracker;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A checklist item inside a project.
 *
 * assignee_user_id is a PLAIN foreign key to users, NOT a composite FK to
 * tracker_members. That asymmetry with project_assignees is deliberate and is
 * documented at length in the migration: a cascading composite FK here
 * hard-deleted the task row when a member was removed. Membership is enforced in
 * the application layer instead (FR-5.4).
 */
#[ScopedBy([TrackerVisibilityScope::class, ProjectPrivacyScope::class])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTracker, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'position' => 'decimal:10',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * Late against its due date, in the org's calendar — see Project::isOverdue()
     * for why the comparison is date-to-date and not ->isPast(). A done task is
     * never overdue no matter when it was ticked.
     */
    public function isOverdue(): bool
    {
        return ! $this->is_done
            && $this->due_date !== null
            && $this->due_date->toDateString()
                < now(config('worktrack.default_timezone'))->toDateString();
    }
}
