<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use Database\Factories\TrackerFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The admin-created container: its own steps, its own member list (D8).
 *
 * SELF-SCOPED. The scope filters on trackers.id rather than a tracker_id column —
 * see TrackerVisibilityScope::columnFor(). Without that, Tracker::all() lists every
 * tracker in the company to anyone, which is the most direct possible violation of
 * FR-2.9 (VERIFICATION.md authorization-1).
 */
#[ScopedBy(TrackerVisibilityScope::class)]
class Tracker extends Model
{
    /** @use HasFactory<TrackerFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** ULIDs in URLs so shareable links never leak how many trackers exist (DD-19). */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function steps(): HasMany
    {
        return $this->hasMany(Step::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TrackerMember::class);
    }

    /**
     * Archived trackers are hidden from normal use but preserved for reporting
     * (FR-2.7). Deliberately opt-IN rather than a global scope: if a report author
     * forgets this, an archived tracker shows on a list — loud and cosmetic. With a
     * global scope, forgetting withTrashed() silently under-reports throughput, and
     * a plausible-looking wrong number survives review indefinitely (DD-3).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
