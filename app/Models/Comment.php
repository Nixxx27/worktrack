<?php

namespace App\Models;

use App\Authorization\Scopes\ProjectPrivacyScope;
use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Models\Concerns\BelongsToTracker;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A comment on a project (FR-4.5).
 *
 * Soft-deleted rather than removed: a deleted comment still has to leave a trace in
 * the conversation, otherwise a thread that read as an argument reads afterwards as
 * agreement. The activity row recording the deletion is not enough on its own — it
 * lives on a different screen from the thread it changed.
 */
#[ScopedBy([TrackerVisibilityScope::class, ProjectPrivacyScope::class])]
class Comment extends Model
{
    use BelongsToTracker, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Resolved once at write time.
     *
     * The notification dispatcher MUST re-assert membership at SEND time — see
     * VERIFICATION.md data-model-2, where freezing authorization at enqueue time let a
     * suspended ex-member receive a digest naming a tracker they could no longer see.
     */
    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_mentions');
    }
}
