<?php

namespace App\Authorization;

use App\Authorization\Exceptions\MissingAccessContextException;
use App\Models\User;

/**
 * Who is asking, and which trackers may they see?
 *
 * This is the input to TrackerVisibilityScope, which is the actual enforcement.
 * The context itself is deliberately dumb — it resolves a set of tracker ids and
 * nothing more — so that the whole isolation boundary stays small enough to read
 * in one sitting (docs/ARCHITECTURE.md §3.2).
 *
 * FAILS CLOSED AND LOUD. An unestablished context throws rather than returning an
 * empty set. An empty set would look like "this user legitimately sees nothing",
 * which is indistinguishable from a bug and would hide the bug forever.
 */
final class AccessContext
{
    private const MODE_UNSET = 'unset';

    private const MODE_GUEST = 'guest';

    private const MODE_USER = 'user';

    private const MODE_SYSTEM = 'system';

    private string $mode = self::MODE_UNSET;

    private ?User $user = null;

    /** @var array<int,int>|null Lazily resolved, then memoised for the request. */
    private ?array $trackerIds = null;

    public function forUser(User $user): void
    {
        $this->mode = self::MODE_USER;
        $this->user = $user;
        $this->trackerIds = null;
    }

    public function guest(): void
    {
        $this->mode = self::MODE_GUEST;
        $this->user = null;
        $this->trackerIds = [];
    }

    /**
     * Full cross-tracker access. Reachable ONLY through SystemContext::run(),
     * which is one of exactly two bypasses in the system.
     */
    public function system(): void
    {
        $this->mode = self::MODE_SYSTEM;
        $this->user = null;
        $this->trackerIds = null;
    }

    public function reset(): void
    {
        $this->mode = self::MODE_UNSET;
        $this->user = null;
        $this->trackerIds = null;
    }

    public function isEstablished(): bool
    {
        return $this->mode !== self::MODE_UNSET;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * Admins see every tracker (D9), and so does system context.
     *
     * Note the deliberate ordering: a suspended admin is NOT an admin here, because
     * status is checked first. Status gates everything.
     */
    public function seesAllTrackers(): bool
    {
        $this->assertEstablished();

        if ($this->mode === self::MODE_SYSTEM) {
            return true;
        }

        return $this->user !== null
            && $this->user->isActive()
            && $this->user->role->seesAllTrackers();
    }

    /**
     * The tracker ids this actor may see.
     *
     * Resolved fresh once per request with no cross-request cache. That is
     * deliberate: FR-2.4 requires member removal to take effect immediately, and any
     * cache added here silently downgrades that to eventual unless it is invalidated
     * on every membership write.
     *
     * @return array<int,int>
     */
    public function visibleTrackerIds(): array
    {
        $this->assertEstablished();

        if ($this->trackerIds !== null) {
            return $this->trackerIds;
        }

        // A non-active user sees nothing, whatever their role or memberships.
        if ($this->user === null || ! $this->user->isActive()) {
            return $this->trackerIds = [];
        }

        return $this->trackerIds = $this->user->trackerMemberships()
            ->pluck('tracker_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isMemberOf(int $trackerId): bool
    {
        return $this->seesAllTrackers()
            || in_array($trackerId, $this->visibleTrackerIds(), true);
    }

    /**
     * Stable cache key for scoped aggregate queries.
     *
     * VERIFICATION.md metrics-2: a dashboard cache keyed WITHOUT the viewer's
     * tracker set serves one user's cross-tracker roll-up to another with different
     * memberships — a silent NFR-S3 breach that produces no error and would never
     * surface in functional testing. Any cached metric MUST include this.
     */
    public function cacheKey(): string
    {
        if ($this->seesAllTrackers()) {
            return 'ctx:all';
        }

        $ids = $this->visibleTrackerIds();
        sort($ids);

        return 'ctx:'.hash('xxh128', implode(',', $ids));
    }

    private function assertEstablished(): void
    {
        if ($this->mode === self::MODE_UNSET) {
            throw new MissingAccessContextException(
                'No AccessContext is bound. A scoped query ran outside a request, a job, or an '
                .'explicit SystemContext::run()/UserContext::runAs() block. Refusing to return rows: '
                .'returning an empty set here would be indistinguishable from a legitimate empty result '
                .'and would hide the bug.'
            );
        }
    }
}
