<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Destroys a user's live credentials: their server-side sessions AND every
 * outstanding "remember me" cookie.
 *
 * FR-1.7 requires a suspended user to be denied on their very NEXT request, not at
 * next login. Two things make that true: status is read from the database row on
 * every request, and this wipes any live session so there is nothing to resume.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHY THE REMEMBER TOKEN IS CYCLED HERE (supersedes AUTH-D10)
 *
 * Remember-me used to be disabled system-wide precisely because of this class: a
 * recaller cookie re-authenticates through EloquentUserProvider::retrieveByToken()
 * without consulting the `sessions` table at all, so deleting sessions could not
 * reach it. A suspended user closing and reopening their browser would be silently
 * re-admitted.
 *
 * Cycling `remember_token` closes that at the source. The recaller cookie carries
 * `id|remember_token|hmac`, and retrieveByToken() hash_equals() the cookie's token
 * against the column — so overwriting the column invalidates every cookie ever
 * issued for this account, on every device, immediately and irreversibly.
 *
 * The write is deliberately a raw query builder UPDATE, not $user->save():
 *   - it cannot be clobbered by a stale in-memory model the caller still holds,
 *   - it fires no model events, so nothing can hook in and swallow it, and
 *   - it runs inside the caller's transaction, so a suspension either revokes both
 *     credential types or neither.
 *
 * It runs BEFORE the session-driver check and outside it, because the remember
 * cookie exists regardless of which session driver is configured. Ordering the two
 * the other way round was the original bug this comment exists to prevent.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class SessionRevoker
{
    /**
     * @return int sessions deleted; the remember-token cycle is unconditional and
     *             is not reflected in this count
     */
    public function revokeAll(int $userId): int
    {
        $this->invalidateRememberCookies($userId);

        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table('sessions')->where('user_id', $userId)->delete();
    }

    /**
     * Overwrite the token every outstanding recaller cookie is validated against.
     *
     * Random rather than NULL: retrieveByToken() already refuses an empty stored
     * token, but a non-empty random value means the column never sits in a state
     * where a future change to that guard could turn "no token" into "any token".
     */
    private function invalidateRememberCookies(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'remember_token' => Str::random(60),
        ]);
    }
}
