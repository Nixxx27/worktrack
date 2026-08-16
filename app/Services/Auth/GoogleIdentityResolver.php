<?php

namespace App\Services\Auth;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\Exceptions\AccountLinkBlockedException;
use App\Services\Auth\Exceptions\EmailNotVerifiedException;
use App\Services\Auth\Exceptions\SignupBlockedException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns a Google callback into a local user, or refuses.
 *
 * Signup is open to any Google account (D6) because the dev team uses personal
 * Gmail — so every rule here is about making that safe without a domain allowlist.
 */
class GoogleIdentityResolver
{
    public function __construct(
        private AuditLogger $audit,
        private SignupGate $gate,
    ) {}

    /**
     * @throws EmailNotVerifiedException|AccountLinkBlockedException|SignupBlockedException
     */
    public function resolve(SocialiteUser $googleUser, string $ip, ?string $userAgent = null): User
    {
        $sub = $googleUser->getId();
        $email = strtolower((string) $googleUser->getEmail());

        // An unverified Google email proves nothing about who controls the address.
        if (! $this->emailIsVerified($googleUser)) {
            $this->audit->signupAttempt($ip, $email, $sub, 'email_unverified', $userAgent);

            throw new EmailNotVerifiedException;
        }

        // IDENTITY IS THE `sub` CLAIM, NEVER THE EMAIL (AUTH-D1).
        // Google emails are mutable and reassignable; sub is not. Matching on email
        // would mean an address change, or a reissued Workspace address, could hand
        // someone another person's account.
        $existing = User::where('google_id', $sub)->first();

        if ($existing !== null) {
            $this->audit->signupAttempt($ip, $email, $sub, 'returning_user', $userAgent);

            // Keep display data fresh, but never touch role, status or identity.
            $existing->forceFill([
                'name' => $googleUser->getName() ?: $existing->name,
                'avatar_url' => $googleUser->getAvatar(),
                'last_login_at' => now(),
            ])->save();

            return $existing;
        }

        // NO AUTOMATIC ACCOUNT LINKING, EVER (AUTH-D2).
        // An email that matches an existing row but carries a different sub is a hard
        // deny. This is what stops someone who controls a Gmail address from claiming
        // an existing account — including the break-glass admin, whose email is
        // guessable. Linking requires a deliberate server-side artisan action.
        if (User::where('email', $email)->exists()) {
            $this->audit->signupAttempt($ip, $email, $sub, 'link_denied', $userAgent);
            $this->audit->linkBlocked($email, $sub, $ip);

            throw new AccountLinkBlockedException;
        }

        $this->gate->assertMayRegister($email, $sub, $ip, $userAgent);

        return DB::transaction(function () use ($googleUser, $sub, $email, $ip, $userAgent) {
            $user = User::create([
                'name' => $googleUser->getName() ?: explode('@', $email)[0],
                'email' => $email,
                'email_verified_at' => now(),
                'google_id' => $sub,
                'avatar_url' => $googleUser->getAvatar(),
                'auth_provider' => AuthProvider::Google,

                // FR-1.2 — role is pre-assigned as Viewer but inert while Pending.
                // Approval is a deliberate admin action, never implied by signing up.
                'role' => UserRole::Viewer,
                'status' => UserStatus::Pending,
                'timezone' => config('worktrack.default_timezone', 'Asia/Manila'),
                'last_login_at' => now(),
            ]);

            $this->audit->signupAttempt($ip, $email, $sub, 'created', $userAgent);
            $this->audit->log('auth.signup.created', $user->id, ['email' => $email], $ip);

            return $user;
        });
    }

    /**
     * Socialite normalises only a subset of claims, so read the raw payload.
     * Treat a missing claim as unverified — fail closed.
     */
    private function emailIsVerified(SocialiteUser $googleUser): bool
    {
        $raw = method_exists($googleUser, 'getRaw') ? $googleUser->getRaw() : [];

        return ($raw['email_verified'] ?? false) === true;
    }
}
