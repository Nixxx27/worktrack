<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Keep me signed in" (FR-1.11).
 *
 * The awkward part of this feature is not the cookie, it is the OAuth round trip.
 * The checkbox is on OUR sign-in page, but Auth::login() does not happen until the
 * callback returns from Google — a different request, with the form long gone. So
 * the intent is parked in the pre-auth session on the way out and consumed on the
 * way back in.
 *
 * Using the session as the carrier rather than the OAuth `state` parameter is
 * deliberate: Socialite owns `state` and uses it for CSRF, and threading an extra
 * value through it would mean either encoding our own payload into a parameter
 * whose whole job is to be opaque and compared for equality, or adding a second
 * round-trip parameter Google would have to echo back. The session already survives
 * the round trip for exactly this class of state.
 *
 * Worth being explicit about the threat model, since this is a pre-authentication
 * write: the parked value only ever lengthens the lifetime of the cookie issued to
 * whoever completes the sign-in. An attacker who could plant it would already need
 * to control the victim's pre-auth session, and the value grants no access it does
 * not already imply. It is pulled (not read) so it can never leak into a later
 * sign-in on the same browser.
 */
class RememberMe
{
    /**
     * Namespaced under `auth.` so it is obvious in a session dump that this is
     * authentication state and not something a component parked there.
     */
    private const SESSION_KEY = 'auth.remember_me';

    /**
     * Park the user's choice before we hand off to Google.
     *
     * A missing parameter means "no" rather than "use the configured default": the
     * checkbox is the user's stated choice, and an unticked HTML checkbox submits
     * nothing at all. The config default governs how the box is RENDERED, which is
     * where a default belongs.
     */
    public function capture(Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $request->boolean('remember'));
    }

    /**
     * Read and clear the choice on return from Google.
     *
     * Cleared unconditionally, including when the feature has since been switched
     * off, so a flag parked before a config change cannot be honoured after it.
     */
    public function consume(Request $request): bool
    {
        $requested = (bool) $request->session()->pull(self::SESSION_KEY, false);

        return $this->enabled() && $requested;
    }

    /**
     * Sign the user in, issuing a bounded recaller cookie only when asked.
     *
     * The duration is set on the guard rather than left at Laravel's 400-day default
     * — see config('worktrack.auth.remember_days'). It is set only on the remembering
     * path so a non-remembered login cannot mutate guard state for the rest of the
     * request.
     */
    public function login(User $user, bool $remember): void
    {
        $guard = Auth::guard('web');

        if ($remember && $guard instanceof SessionGuard) {
            $guard->setRememberDuration($this->durationMinutes());
        }

        $guard->login($user, $remember);
    }

    public function enabled(): bool
    {
        return (bool) config('worktrack.auth.remember_enabled', true);
    }

    /** Governs the checkbox's initial state only — never whether a cookie is issued. */
    public function defaultChecked(): bool
    {
        return $this->enabled() && (bool) config('worktrack.auth.remember_default', true);
    }

    public function durationMinutes(): int
    {
        return max(1, (int) config('worktrack.auth.remember_days', 30)) * 24 * 60;
    }
}
