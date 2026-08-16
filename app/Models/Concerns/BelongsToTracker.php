<?php

namespace App\Models\Concerns;

use App\Models\Tracker;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that carries a denormalized tracker_id.
 *
 * The #[ScopedBy] attribute on the model is what actually attaches the scope; this
 * trait carries the relation and the immutability guard.
 */
trait BelongsToTracker
{
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    /**
     * tracker_id is IMMUTABLE. Projects can never move between trackers (settled
     * decision, docs/ARCHITECTURE.md §11 OQ12), and the whole isolation design is
     * licensed by that: the composite foreign keys make a cross-tracker rewrite
     * fail at the engine level anyway (verified — errno 1451), so this guard exists
     * to fail early with a readable message rather than a raw SQL error.
     */
    public static function bootBelongsToTracker(): void
    {
        static::updating(function ($model) {
            if ($model->isDirty('tracker_id')) {
                throw new \LogicException(
                    'tracker_id is immutable. Moving a record between trackers is deliberately '
                    .'out of scope: it would retroactively re-attribute historical metrics to a '
                    .'tracker that did not do the work, and the composite foreign keys would '
                    .'reject it regardless. Archive and recreate instead.'
                );
            }
        });
    }
}
