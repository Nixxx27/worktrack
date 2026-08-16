<?php

namespace App\Models;

use App\Authorization\Scopes\TrackerVisibilityScope;
use App\Models\Concerns\BelongsToTracker;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file in R2 (FR-6).
 *
 * The row is the authority on whether an object may be served, and `status` is the
 * field that says so. A row is created 'pending' BEFORE the PUT and promoted to
 * 'available' only once R2 confirms, so nothing but 'available' is ever listed or
 * signable and a failed upload cannot leave something half-visible (FR-6.8).
 *
 * tracker_id is denormalized onto this table on purpose: the FR-6.4 check on
 * signed-URL issuance is then one indexed lookup rather than a traversal from
 * attachment to task to project to tracker. That traversal is exactly the shape of
 * code where an isolation bug hides.
 */
#[ScopedBy(TrackerVisibilityScope::class)]
class Attachment extends Model
{
    use BelongsToTracker, HasUlids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'purged_at' => 'datetime',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** The only status that may be listed, signed or downloaded. */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function isImage(): bool
    {
        // SVG is excluded from inline preview even though it is an allowed upload:
        // it is an XML document that can carry script, and rendering one inline is
        // handing the page over to whoever uploaded it.
        return str_starts_with($this->mime_type, 'image/')
            && $this->mime_type !== 'image/svg+xml';
    }

    /** Human-readable size for the card UI. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $unit === 'B' ? "{$bytes} B" : round($bytes, 1)." {$unit}";
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
