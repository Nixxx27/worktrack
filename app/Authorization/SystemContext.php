<?php

namespace App\Authorization;

use App\Authorization\Exceptions\SystemContextUnavailableException;
use Closure;

/**
 * The all-trackers bypass. One of exactly two in the system.
 *
 * Reserved for genuinely tracker-agnostic background work: the nightly stall sweep,
 * cache rebuilds, cross-tracker maintenance. NOT for per-user work — a queued
 * export or a per-recipient digest must use UserContext::runAs() instead, or it
 * will happily render every tracker in the system into one member's CSV.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CRITICAL FIX — VERIFICATION.md authorization-3.
 *
 * The original design gated this on app()->runningInConsole(), and called that a
 * "runtime guarantee" that converts a review convention into structural
 * impossibility. It is not one: runningInConsole() resolves to PHP_SAPI === 'cli',
 * and PHPUnit/Pest run under the CLI SAPI. $this->get() in a feature test does not
 * change PHP_SAPI — it dispatches a synthetic Request through the kernel in the
 * same CLI process. So the guard was OPEN in every feature test, and the design's
 * own cited proof (a test asserting an HTTP request throws) would have received a
 * 200 with rows.
 *
 * The predictable repair — mocking runningInConsole(), or forcing
 * APP_RUNNING_IN_CONSOLE in phpunit.xml — asserts the guard against a mocked value
 * and never against real behaviour. Six months later someone adds a plausible
 * SystemContext::run() inside an admin controller action, every test passes, and it
 * throws a 500 in production under PHP-FPM. The inverse repair is worse: it makes
 * every legitimate use throw in tests, and the fix for THAT is to relax the guard.
 *
 * So the latch is not derived from the SAPI. It asks a question with a real answer
 * in both environments: does this execution carry a user's HTTP session? A web
 * request does. A queue worker and a scheduled command do not. That is testable in
 * both directions with no mocking.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
final class SystemContext
{
    /**
     * Run $callback with full cross-tracker visibility, restoring the previous
     * context afterwards even if the callback throws.
     *
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        self::assertPermitted();

        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        $previousUser = $context->user();
        $wasEstablished = $context->isEstablished();

        $context->system();

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

    /**
     * Permitted only where no user session exists: queue workers, scheduled
     * commands, console commands. Denied inside a real HTTP request, where an
     * all-trackers bypass would be a direct NFR-S3 breach.
     */
    private static function assertPermitted(): void
    {
        if (! app()->bound('request')) {
            return;   // console / worker with no request bound at all
        }

        $request = app('request');

        if (method_exists($request, 'hasSession') && $request->hasSession()) {
            throw new SystemContextUnavailableException(
                'SystemContext::run() was called from a request carrying a user session. '
                .'This bypass grants visibility of every tracker and must never be reachable '
                .'from user-facing code. If this is per-user background work, use '
                .'UserContext::runAs($user) instead.'
            );
        }
    }
}
