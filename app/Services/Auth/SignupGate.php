<?php

namespace App\Services\Auth;

use App\Services\Auth\Exceptions\SignupBlockedException;
use App\Services\Auth\Exceptions\SignupRateLimitedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Abuse controls on the one publicly-reachable write path in the system.
 *
 * Because signup is open to any Google account (D6), the approval queue is exposed
 * to the internet. Nothing here restricts WHO may apply — that would defeat the
 * decision — it restricts how fast, and remembers refusals.
 */
class SignupGate
{
    public function __construct(
        private EmailCanonicalizer $canonicalizer,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws SignupBlockedException|SignupRateLimitedException
     */
    public function assertMayRegister(string $email, string $googleId, string $ip, ?string $userAgent = null): void
    {
        $canonical = $this->canonicalizer->canonicalize($email);

        $blocked = DB::table('blocked_emails')
            ->where('canonical_email', $canonical)
            ->orWhere(fn ($q) => $q->whereNotNull('google_id')->where('google_id', $googleId))
            ->exists();

        if ($blocked) {
            $this->audit->signupAttempt($ip, $email, $googleId, 'blocked_email', $userAgent);

            throw new SignupBlockedException;
        }

        // The load-bearing limiter (AUTH-D19): it counts ACCOUNT CREATIONS, not
        // requests, and is evaluated after Google has verified identity but before a
        // row is inserted. Limiting requests alone would not stop someone with many
        // Google accounts from filling the queue.
        $perHour = RateLimiter::attempt(
            key: 'signup-create:'.$ip,
            maxAttempts: (int) config('worktrack.signup.per_ip_per_hour', 3),
            callback: fn () => true,
            decaySeconds: 3600,
        );

        $perDay = RateLimiter::attempt(
            key: 'signup-create-day:'.$ip,
            maxAttempts: (int) config('worktrack.signup.per_ip_per_day', 10),
            callback: fn () => true,
            decaySeconds: 86400,
        );

        if (! $perHour || ! $perDay) {
            $this->audit->signupAttempt($ip, $email, $googleId, 'rate_limited', $userAgent);

            throw new SignupRateLimitedException;
        }
    }

    /** FR-1.6 — a rejected address can be stopped from refilling the queue. */
    public function block(string $email, ?string $googleId, ?int $byUserId, ?string $reason = null): void
    {
        DB::table('blocked_emails')->updateOrInsert(
            ['canonical_email' => $this->canonicalizer->canonicalize($email)],
            [
                'original_email' => $email,
                'google_id' => $googleId,
                'reason' => $reason,
                'blocked_by_user_id' => $byUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
