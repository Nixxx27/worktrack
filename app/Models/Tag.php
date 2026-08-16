<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Models\Concerns\BelongsToTracker;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A free-typed label, owned by one tracker.
 *
 * Scoped like every other tracker-owned model, which matters more here than it looks:
 * the tag autocomplete is a *listing* endpoint over user-authored text, so an
 * unscoped read would hand a Member of one tracker the vocabulary of every other one.
 */
#[ScopedBy(TrackerVisibilityScope::class)]
class Tag extends Model
{
    use BelongsToTracker;

    protected $guarded = ['id'];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_tags')->withPivot('tagged_at');
    }
}
