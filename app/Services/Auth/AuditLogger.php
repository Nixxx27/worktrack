<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Append-only audit writes.
 *
 * Deliberately synchronous and deliberately NOT queued (AUTH-D15). A queued audit
 * write is a lost audit write when the worker is down — and the events recorded here
 * are exactly the ones you need after an incident. Losing them because a background
 * process was stopped defeats the purpose of having them.
 */
class AuditLogger
{
    public function log(string $action, ?int $actorId = null, array $context = [], ?string $ip = null, ?int $trackerId = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_user_id' => $actorId,
            'action' => $action,
            'tracker_id' => $trackerId,
            'ip_address' => $ip ?? request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 512) ?: null,
            'context' => $context ? json_encode($context) : null,
            'created_at' => now(),
        ]);
    }

    public function signupAttempt(string $ip, ?string $email, ?string $googleId, string $outcome, ?string $userAgent = null): void
    {
        DB::table('signup_attempts')->insert([
            'ip_address' => $ip,
            'email' => $email,
            'google_id' => $googleId,
            'outcome' => $outcome,
            'user_agent' => $userAgent ? substr($userAgent, 0, 512) : null,
            'created_at' => now(),
        ]);
    }

    public function linkBlocked(string $email, string $googleId, string $ip): void
    {
        $this->log('auth.google.link_blocked', null, [
            'email' => $email,
            'google_sub' => $googleId,
        ], $ip);
    }

    /** Break-glass attempts record a reason code and NEVER the submitted password. */
    public function breakGlassAttempt(string $reason, ?int $userId, string $ip): void
    {
        $this->log('auth.break_glass.attempt', $userId, ['reason' => $reason], $ip);
    }
}
