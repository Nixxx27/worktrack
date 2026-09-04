<?php

namespace App\Models;

use App\Authorization\Scopes\ProjectPrivacyScope;
use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Enums\HealthSource;
use App\Enums\ProjectHealth;
use App\Enums\ProjectVisibility;
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
#[ScopedBy([TrackerVisibilityScope::class, ProjectPrivacyScope::class])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToTracker, HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'health' => ProjectHealth::class,
            'visibility' => ProjectVisibility::class,
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

    /**
     * Comments on this card.
     *
     * Added for universal search (FR-4.10), which has to be able to ask "does this card
     * have a comment mentioning X" as a subquery rather than by loading every thread.
     * The drawer reads comments through CommentService and does not need this.
     *
     * Comment is soft-deleted, so the relation excludes withdrawn comments without
     * asking: search must never resurface a card only findable through a comment its
     * author deleted.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
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

    /** FR-4.11 — a card only its owner can see. */
    public function isPrivate(): bool
    {
        return $this->visibility === ProjectVisibility::Private;
    }

    /**
     * Shared cards only — the shape every AGGREGATE has to use.
     *
     * ProjectPrivacyScope already hides other people's private cards, so this exists
     * for the one case the scope cannot decide on its own: the viewer's OWN private
     * work. On the board that work must appear, which is the whole feature. In a
     * metric it must not, for two separate reasons.
     *
     * The first is that a private card is not team throughput. A cycle time that
     * silently includes one person's private notes is a figure two people reading the
     * same dashboard disagree about, with nothing on screen to explain why.
     *
     * The second is a leak. Metrics are cached under AccessContext::cacheKey(), which
     * distinguishes viewers only by their TRACKER SET — so two members of the same
     * trackers share a cache entry, and the first one to warm it would serve their own
     * private cards' numbers to the second. Excluding private work here is what keeps
     * that key correct; see the note on cacheKey().
     */
    public function scopeExcludingPrivate(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('visibility'), ProjectVisibility::Tracker->value);
    }

    /** The open movement row — the one with no exit. At most one exists, by DB invariant. */
    public function currentMovement(): ?ProjectStepMovement
    {
        return $this->movements()->whereNull('exited_at')->first();
    }

    /**
     * Late against the promised date, in the org's calendar.
     *
     * Two traps live here, which is why the board, the drawer and anything else
     * asking the question must call this rather than re-deriving it.
     *
     * target_date is a bare date, so its Carbon is midnight in app.timezone (UTC).
     * ->isPast() therefore turns true at 08:00 Manila *on the due day itself* and
     * paints a card red through the whole working day it was promised for. The
     * comparison has to be date-to-date in the org timezone (NFR-U5), matching
     * MetricsRepository::deadlines(); Y-m-d strings order correctly, so comparing
     * them keeps one rule with no instant arithmetic to get wrong.
     *
     * Overdue is also only asked of a card still in flight. A finished project
     * that was late is a fact for the report, not a red flag on the board.
     */
    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && $this->current_step_type !== StepType::Terminal
            && $this->target_date->toDateString()
                < now(config('worktrack.default_timezone'))->toDateString();
    }

    /**
     * How long the commitment runs in working days, weekends excluded.
     *
     * Counted INCLUSIVELY on both ends: a card promised for the Tuesday it starts
     * on is one day of work, not zero, and "17 Aug → 24 Aug" is the six weekdays
     * a person would count off a calendar, not the seven-day gap between them.
     *
     * Null rather than 0 when either end is missing, so a caller cannot print
     * "0 working days" for a card whose span is simply unknown. A target before
     * the start is also null: it is bad data, and inventing a negative or a zero
     * for it only hides that.
     *
     * Weekends are Sat/Sun only. Holidays are not modelled anywhere in the app,
     * so this deliberately does not pretend to know them.
     */
    public function workingDays(): ?int
    {
        if ($this->start_date === null || $this->target_date === null) {
            return null;
        }

        $start = $this->start_date->copy()->startOfDay();
        $end = $this->target_date->copy()->startOfDay();

        if ($end->lt($start)) {
            return null;
        }

        // Whole weeks are five days each by definition; only the leftover tail
        // needs walking, so a multi-year span costs the same six iterations as
        // a one-week one.
        $totalDays = (int) $start->diffInDays($end) + 1;
        $working = intdiv($totalDays, 7) * 5;

        $day = $start->copy()->addDays(intdiv($totalDays, 7) * 7);

        for ($i = $totalDays % 7; $i > 0; $i--) {
            if (! $day->isWeekend()) {
                $working++;
            }

            $day->addDay();
        }

        return $working;
    }
}
