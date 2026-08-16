<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\Exceptions\IllegalStateTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a user's role or status flows through here — from the admin screen
 * and from artisan alike. One implementation means the CLI cannot quietly bypass a
 * guard rail the UI enforces.
 *
 * The guard rails (AUTH-D27) exist because the failure they prevent is unrecoverable
 * without shell access: an admin who demotes themselves, or suspends the last
 * remaining admin, locks the organisation out of its own system. Break-glass is the
 * escape hatch, and it deliberately costs a credential rotation every time it is
 * used — so it should never be the routine remedy for an avoidable mistake.
 */
class UserStateMachine
{
    /** Which statuses may legally follow which. */
    private const TRANSITIONS = [
        'pending' => [UserStatus::Active, UserStatus::Rejected, UserStatus::Blocked],
        'active' => [UserStatus::Suspended],
        'suspended' => [UserStatus::Active, UserStatus::Blocked],
        'rejected' => [UserStatus::Active, UserStatus::Blocked],
        'blocked' => [UserStatus::Active],
    ];

    public function __construct(
        private AuditLogger $audit,
        private SessionRevoker $sessions,
        private SignupGate $gate,
    ) {}

    /** FR-1.5 — approve a pending signup and set their starting role. */
    public function approve(User $user, UserRole $role, ?User $actor = null): User
    {
        $this->assertNotSelf($user, $actor, 'approve');

        return DB::transaction(function () use ($user, $role, $actor) {
            $this->assertTransitionAllowed($user, UserStatus::Active);

            $user->forceFill([
                'status' => UserStatus::Active,
                'role' => $role,
                'approved_at' => now(),
                'approved_by_user_id' => $actor?->id,
            ])->save();

            $this->audit->log('user.approved', $actor?->id, [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $role->value,
            ]);

            return $user;
        });
    }

    public function reject(User $user, ?User $actor = null, bool $blockFutureSignups = false): User
    {
        $this->assertNotSelf($user, $actor, 'reject');

        return DB::transaction(function () use ($user, $actor, $blockFutureSignups) {
            $this->assertTransitionAllowed($user, UserStatus::Rejected);

            $user->forceFill(['status' => UserStatus::Rejected])->save();

            // Revoke immediately — FR-1.7's "next request", not "next login".
            $this->sessions->revokeAll($user->id);

            if ($blockFutureSignups) {
                // FR-1.6 — otherwise the same person re-signs up and refills the queue.
                $this->gate->block($user->email, $user->google_id, $actor?->id, 'rejected');
            }

            $this->audit->log('user.rejected', $actor?->id, [
                'user_id' => $user->id,
                'email' => $user->email,
                'blocked' => $blockFutureSignups,
            ]);

            return $user;
        });
    }

    public function suspend(User $user, ?User $actor = null): User
    {
        $this->assertNotSelf($user, $actor, 'suspend');
        $this->assertNotLastAdmin($user, 'suspend');
        $this->assertNotBreakGlass($user, 'suspend');

        return DB::transaction(function () use ($user, $actor) {
            $this->assertTransitionAllowed($user, UserStatus::Suspended);

            $user->forceFill(['status' => UserStatus::Suspended])->save();
            $revoked = $this->sessions->revokeAll($user->id);

            $this->audit->log('user.suspended', $actor?->id, [
                'user_id' => $user->id,
                'email' => $user->email,
                'sessions_revoked' => $revoked,
            ]);

            return $user;
        });
    }

    public function reactivate(User $user, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $actor) {
            $this->assertTransitionAllowed($user, UserStatus::Active);

            $user->forceFill(['status' => UserStatus::Active])->save();

            $this->audit->log('user.reactivated', $actor?->id, [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $user;
        });
    }

    public function setRole(User $user, UserRole $role, ?User $actor = null): User
    {
        $this->assertNotSelf($user, $actor, 'change the role of');
        $this->assertNotBreakGlass($user, 'change the role of');

        if ($user->role === UserRole::Admin && $role !== UserRole::Admin) {
            $this->assertNotLastAdmin($user, 'demote');
        }

        return DB::transaction(function () use ($user, $role, $actor) {
            $previous = $user->role;
            $user->forceFill(['role' => $role])->save();

            $this->audit->log('user.role_changed', $actor?->id, [
                'user_id' => $user->id,
                'email' => $user->email,
                'from' => $previous->value,
                'to' => $role->value,
            ]);

            return $user;
        });
    }

    // ── guard rails ─────────────────────────────────────────────────────────

    private function assertTransitionAllowed(User $user, UserStatus $target): void
    {
        $allowed = self::TRANSITIONS[$user->status->value] ?? [];

        if (! in_array($target, $allowed, true)) {
            throw new IllegalStateTransitionException(
                "Cannot move {$user->email} from {$user->status->value} to {$target->value}."
            );
        }
    }

    /**
     * An admin acting on their own account is how lockouts happen. Requiring a second
     * person is cheap; recovering from a self-demotion is not.
     */
    private function assertNotSelf(User $user, ?User $actor, string $verb): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw new IllegalStateTransitionException(
                "You cannot {$verb} your own account. Ask another administrator."
            );
        }
    }

    private function assertNotLastAdmin(User $user, string $verb): void
    {
        if ($user->role !== UserRole::Admin || ! $user->isActive()) {
            return;
        }

        $remaining = User::where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->where('id', '!=', $user->id)
            ->where('is_break_glass', false)
            ->count();

        if ($remaining === 0) {
            throw new IllegalStateTransitionException(
                "Refusing to {$verb} the last active administrator — that would lock everyone out. "
                .'Promote another administrator first.'
            );
        }
    }

    /**
     * The emergency account is managed by artisan only. Letting it be edited through
     * the UI would mean a compromised admin session could disable the recovery path
     * before doing anything else.
     */
    private function assertNotBreakGlass(User $user, string $verb): void
    {
        if ($user->is_break_glass) {
            throw new IllegalStateTransitionException(
                "The break-glass account cannot be modified here. Use artisan to {$verb} it."
            );
        }
    }
}
