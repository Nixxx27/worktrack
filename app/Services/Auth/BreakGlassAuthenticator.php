<?php

namespace App\Services\Auth;

use App\Enums\AuthProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The emergency admin login. Exists so the owner keeps access when Google SSO is
 * unavailable or the OAuth client is misconfigured (FR-1.8, US-8).
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CRITICAL FIX — VERIFICATION.md auth-lifecycle-1.
 *
 * The original design put a GLOBAL 20-per-hour limiter and a persistent
 * `break_glass_locked_until` row lock AHEAD of the password check, and justified the
 * global cap on the grounds that "legitimate use is roughly zero per year". That
 * same property is what made it an attack: an anonymous attacker only has to keep
 * the counter saturated.
 *
 * The scenario: Google degrades on a Monday morning — the exact situation this path
 * exists for. A scanner has been sending 25 POSTs an hour from rotating VPSs, which
 * is under any WAF threshold and costs nothing. The global limiter is saturated, so
 * the owner's CORRECT password is rejected before Hash::check ever runs. Separately,
 * ten bad guesses against the known break-glass email set a 60-minute row lock that
 * the attacker refreshes hourly, forever. An availability control designed to
 * survive a Google outage had been converted into one an anonymous attacker could
 * hold shut indefinitely — and the only documented remedy was shell access, which
 * is not a control this design provides.
 *
 * The rule that fixes it: A THROTTLE MUST NEVER DENY A REQUEST THAT PRESENTS VALID
 * CREDENTIALS. Counters are consulted only after the password is checked, and only
 * on the failure branch. Success ignores every counter and clears the lock.
 *
 * What is kept: the per-(email+IP) limiter as a hard block, because a legitimate
 * operator retries from one address and an attacker distributing across IPs cannot
 * use it to lock anyone out. The global cap survives only as an alert plus a
 * progressive delay — it raises cost without ever producing a hard deny.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class BreakGlassAuthenticator
{
    /** Any bcrypt hash of a fixed string, used to keep timing flat on unknown accounts. */
    private const DUMMY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.wLLbcqW4z6mmwOaX7Wt/7c1cQMKX8FW';

    public function __construct(private AuditLogger $audit) {}

    public function attempt(string $email, string $password, string $ip): ?User
    {
        // A hard block that an attacker CANNOT use against the operator: it is keyed
        // to the attacker's own IP, and the operator retries from a different one.
        if (RateLimiter::tooManyAttempts($this->perIpKey($email, $ip), 3)) {
            $this->audit->breakGlassAttempt('throttled_email_ip', null, $ip);

            return null;
        }

        // The candidate row is narrowed in SQL so a normal Google account can never
        // be authenticated here even if it somehow carried a password hash.
        $user = User::query()
            ->where('email', $email)
            ->where('is_break_glass', true)
            ->where('auth_provider', AuthProvider::Local)
            ->whereNotNull('password')
            ->first();

        // ALWAYS hash-check, even when no row matched, so response time does not
        // reveal whether the account exists.
        $hash = $user->password ?? self::DUMMY_HASH;
        $passwordValid = Hash::check($password, $hash);

        if ($user === null || ! $passwordValid) {
            $this->registerFailure($email, $ip, $user);

            return null;
        }

        // ── valid credentials from here on ──────────────────────────────────────
        // Deliberately BEFORE any status/lock check, so an attacker's failed attempts
        // cannot stand between the operator and their own account.
        if (! $user->isActive()) {
            $this->audit->breakGlassAttempt('inactive_account', $user->id, $ip);

            return null;
        }

        RateLimiter::clear($this->perIpKey($email, $ip));

        // A correct password clears the lock rather than being blocked by it: the lock
        // exists to slow guessing, and a correct guess is not guessing.
        $user->forceFill([
            'break_glass_locked_until' => null,
            'break_glass_last_used_at' => now(),
            'break_glass_rotation_required' => true,   // NFR-S8 — every use forces rotation
            'last_login_at' => now(),
        ])->save();

        $this->audit->breakGlassAttempt('success', $user->id, $ip);

        return $user;
    }

    /**
     * Failure bookkeeping. The global counter is recorded for ALERTING and for a
     * progressive delay — never for a hard deny.
     */
    private function registerFailure(string $email, string $ip, ?User $user): void
    {
        RateLimiter::hit($this->perIpKey($email, $ip), 900);
        RateLimiter::hit('break-glass-global', 3600);

        $globalFailures = RateLimiter::attempts('break-glass-global');

        // Cost without denial: a bounded delay proportional to recent global failures.
        // Capped so it can never become a de facto outage.
        //
        // The cap is configuration rather than a literal so the test suite can set it
        // to 0. That is not a convenience — a 40-failure flood test at the production
        // cap added ~30s to every run, and a suite slow enough to skip is a suite that
        // stops catching regressions.
        $cap = (int) config('worktrack.break_glass.failure_delay_cap_ms', 1500);
        $delayMs = min($cap, $globalFailures * 120);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        $this->audit->breakGlassAttempt(
            $user === null ? 'unknown_account' : 'wrong_password',
            $user?->id,
            $ip,
        );

        // Crossing the alert threshold notifies admins that someone is probing the
        // emergency door. It does NOT close the door.
        if ($globalFailures === (int) config('worktrack.break_glass.alert_threshold', 20)) {
            $this->audit->log('auth.break_glass.alert_threshold_reached', null, [
                'failures_last_hour' => $globalFailures,
            ], $ip);
        }
    }

    private function perIpKey(string $email, string $ip): string
    {
        return 'break-glass:'.sha1(strtolower($email).'|'.$ip);
    }
}
