<?php

namespace App\Authorization;

use App\Models\User;
use Closure;

/**
 * Run background work AS a specific user, with that user's tracker visibility.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CRITICAL FIX — VERIFICATION.md authorization-2.
 *
 * The original design had only forUser() (bound by HTTP middleware), guest(), and
 * system() — with no way to bind a per-user context outside a request. Since the
 * scope throws when no context is bound, every queued job touching a scoped model
 * had exactly two outcomes: hard failure, or the all-trackers bypass.
 *
 * Both outcomes were live bugs. Queued mail would die on day one (a mailable view
 * touching $project->tracker->name runs inside Laravel's own SendQueuedMailable,
 * which application code cannot wrap), taking FR-1.6 approval mail with it — so
 * approved users would never learn they were approved. And the obvious repair,
 * wrapping the job in SystemContext::run(), silently turns a Member's queued CSV
 * export into a dump of every project in every tracker.
 *
 * This class is the missing third binder. Any job doing work ON BEHALF OF a user
 * — exports, digests, per-recipient notifications — must carry a user id and open
 * with it. SystemContext is reserved for genuinely tracker-agnostic work.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
final class UserContext
{
    /**
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     */
    public static function runAs(User $user, Closure $callback): mixed
    {
        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        $previousUser = $context->user();
        $wasEstablished = $context->isEstablished();

        $context->forUser($user);

        try {
            return $callback();
        } finally {
            if ($previousUser !== null) {
                $context->forUser($previousUser);
            } elseif ($wasEstablished) {
                $context->guest();
            } else {
                $context->reset();
            }
        }
    }
}
