<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The approval gate (FR-1.3, FR-1.7).
 *
 * Signup is open to any Google account, so this is what makes that safe: a Pending
 * user sees ONLY the waiting screen — no trackers, no projects, no dashboard, no
 * data endpoint, no file. A Suspended user is denied on their very next request,
 * not at next login, because status is read from the freshly-loaded user row rather
 * than from the session.
 *
 * Registered GLOBALLY. A per-route alias would mean any route added later — or
 * Livewire's single update endpoint — silently bypasses the gate. The cost of that
 * choice is that a bug here is a total outage rather than a partial one, so this
 * class must stay short enough to read in one screen and must be covered by a test
 * that enumerates every registered route.
 */
class EnsureAccountActive
{
    /**
     * Routes reachable while not yet approved. Matched on route NAME, not path
     * pattern — a path prefix would silently widen if someone nests a route under it.
     */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.pending',
        'auth.google.redirect',
        'auth.google.callback',
        'break-glass.show',
        'break-glass.attempt',
        'logout',
        'health',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No-op for guests: unauthenticated access is the auth middleware's job.
        if ($user === null) {
            return $next($request);
        }

        if ($user->isActive()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        // Data endpoints must not receive an HTML redirect.
        if ($request->expectsJson()) {
            abort(403, 'Your account is not active.');
        }

        return redirect()->route('auth.pending');
    }
}
