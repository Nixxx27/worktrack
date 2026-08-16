<?php

namespace App\Authorization\Scopes;

use App\Authorization\AccessContext;
use App\Models\Tracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The enforcement point for tracker isolation (NFR-S3 / FR-2.9).
 *
 * Attached by attribute to every tracker-owned model, so a query written a year
 * from now inherits scoping without its author knowing this class exists. That
 * property is the entire point: an isolation control that developers must remember
 * to apply is not a control.
 *
 * Eloquent wraps caller-added conditions via nestWheresForScope(), so an
 * `orWhere()` appended by a caller CANNOT dissolve this predicate — the constraint
 * ends up outside the OR group. That protection does NOT extend to raw query
 * builders, which is why ScopedQueryFactory never hands one out.
 * See VERIFICATION.md authorization-4.
 */
final class TrackerVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        // Admins and system context see everything. Note this still calls through
        // AccessContext, which throws if nothing is bound — so an unbooted job does
        // not silently get an unfiltered query.
        if ($context->seesAllTrackers()) {
            return;
        }

        $builder->whereIn($this->columnFor($model), $context->visibleTrackerIds());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════════
     * CRITICAL FIX — VERIFICATION.md authorization-1.
     *
     * The original scope hardcoded $model->qualifyColumn('tracker_id'). The
     * `trackers` table has `id`, not `tracker_id`, so the Tracker model could not be
     * scoped by this mechanism at all — it appeared in neither the applied list nor
     * the deliberately-exempt list, and the bidirectional architecture test could
     * not catch it because it only checked that the two lists agreed with each
     * other, not that they were complete.
     *
     * The consequence was the most direct possible violation of FR-2.9: the tracker
     * switcher, written the obvious way as Tracker::orderBy('name')->get(), listed
     * every tracker in the company to a Member of one — and to an approved user with
     * ZERO memberships. Clicking one 404'd via the policy, so the route-isolation
     * sweep stayed green while the names and existence had already leaked. The
     * shareable text search over tracker names had the same hole.
     *
     * Making the scope model-aware rather than column-name-fixed closes it.
     * ═══════════════════════════════════════════════════════════════════════════
     */
    private function columnFor(Model $model): string
    {
        return $model instanceof Tracker
            ? $model->getQualifiedKeyName()      // trackers.id — a tracker IS its own scope
            : $model->qualifyColumn('tracker_id');
    }
}
