<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Enums\StepType;
use App\Models\Concerns\BelongsToTracker;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The card that moves through steps — the unit whose timing the whole product measures.
 *
 * The denormalized columns here (current_step_entered_at, tasks_done/total, counts)
 * are a read model rebuildable from project_step_movements. They exist because the
 * board renders 500 cards and the alternative is three correlated subqueries per
 * card. They must ONLY be written by the movement recorder and activity recorder —
 * any other writer silently corrupts every metric downstream with no error.
 */
#[ScopedBy(TrackerVisibilityScope::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTracker, HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'health' => ProjectHealth::class,
            'health_source' => HealthSource::class,
            'current_step_type' => StepType::class,
            'start_date' => 'date',
            'target_date' => 'date',
            'last_activity_at' => 'datetime',
            'current_step_entered_at' => 'datetime',
            'first_active_at' => 'datetime',
            'first_terminal_at' => 'datetime',
            'health_set_at' => 'datetime',
            'stall_notified_at' => 'datetime',
            'archived_at' => 'datetime',
            'skipped_active' => 'boolean',
            'board_position' => 'decimal:10',
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ProjectStepMovement::class);
    }

    /** Restricted to tracker members by a composite FK — a crafted POST cannot defeat it (FR-4.3). */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_assignees')->withPivot('assigned_at');
    }

    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_watchers')->withPivot('watched_at');
    }

    /** Restricted to this tracker's tags by a composite FK — see the tags migration. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'project_tags')->withPivot('tagged_at');
    }

    /** The append-only feed. Read only — ActivityRecorder is the single writer. */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** The open movement row — the one with no exit. At most one exists, by DB invariant. */
    public function currentMovement(): ?ProjectStepMovement
    {
        return $this->movements()->whereNull('exited_at')->first();
    }
}
