<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\Exceptions\IllegalStateTransitionException;
use App\Services\Auth\UserStateMachine;
use Illuminate\Console\Command;

/**
 * User administration from the shell.
 *
 * The `first-admin` action exists to solve a real bootstrap deadlock: approval
 * requires an admin, and a fresh install has none, so the very first signup can
 * never be approved through the application. Every other action here duplicates the
 * admin screen — deliberately, so that a broken UI or a lost admin account never
 * leaves the system unadministrable.
 */
class ManageUsers extends Command
{
    protected $signature = 'worktrack:user
                            {action : list|first-admin|approve|reject|suspend|reactivate|role}
                            {email? : the user to act on}
                            {--role=member : role for approve/role (admin|manager|member|viewer)}
                            {--block : on reject, also block future signups from that address}';

    protected $description = 'List and administer users (approve, reject, suspend, set role)';

    public function handle(UserStateMachine $machine): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'first-admin' => $this->firstAdmin($machine),
                'approve' => $this->approve($machine),
                'reject' => $this->act($machine, 'reject'),
                'suspend' => $this->act($machine, 'suspend'),
                'reactivate' => $this->act($machine, 'reactivate'),
                'role' => $this->setRole($machine),
                default => $this->bail('Unknown action. Use list, first-admin, approve, reject, suspend, reactivate or role.'),
            };
        } catch (IllegalStateTransitionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(): int
    {
        $users = User::orderByRaw("FIELD(status,'pending','active','suspended','rejected','blocked')")
            ->orderBy('email')->get();

        if ($users->isEmpty()) {
            $this->warn('No users yet. Sign in with Google once, then run: worktrack:user first-admin <email>');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'Role', 'Status', 'Sign-in', 'Trackers'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->email.($u->is_break_glass ? ' (break-glass)' : ''),
                $u->role->value,
                $u->status->value,
                $u->auth_provider->value,
                $u->trackerMemberships()->count(),
            ])->all(),
        );

        $admins = User::where('role', UserRole::Admin)->where('status', UserStatus::Active)->count();

        if ($admins === 0) {
            $this->newLine();
            $this->warn('  No active administrator exists. Nobody can approve anyone.');
            $this->line('  Fix: php artisan worktrack:user first-admin <email>');
        }

        return self::SUCCESS;
    }

    /**
     * Bootstrap. Bypasses the normal approval flow because there is by definition
     * nobody to perform it, and refuses once an admin exists so it cannot become a
     * quiet privilege-escalation path.
     */
    private function firstAdmin(UserStateMachine $machine): int
    {
        $existing = User::where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->where('is_break_glass', false)
            ->count();

        if ($existing > 0) {
            $this->error('An active administrator already exists. Use "approve" or "role" instead.');

            return self::FAILURE;
        }

        $user = $this->findUser();
        if ($user === null) {
            return self::FAILURE;
        }

        // No actor is passed: there is genuinely no acting admin, and the audit entry
        // records that this was a shell bootstrap rather than an in-app approval.
        if ($user->status === UserStatus::Pending) {
            $machine->approve($user, UserRole::Admin);
        } else {
            $machine->setRole($user, UserRole::Admin);
        }

        $this->info("{$user->email} is now an active administrator.");
        $this->line('  Sign in again and you will land on the board instead of the waiting screen.');

        return self::SUCCESS;
    }

    private function approve(UserStateMachine $machine): int
    {
        $user = $this->findUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $role = $this->role();
        if ($role === null) {
            return self::FAILURE;
        }

        $machine->approve($user, $role, $this->actor());
        $this->info("Approved {$user->email} as {$role->value}.");

        return self::SUCCESS;
    }

    private function setRole(UserStateMachine $machine): int
    {
        $user = $this->findUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $role = $this->role();
        if ($role === null) {
            return self::FAILURE;
        }

        $machine->setRole($user, $role, $this->actor());
        $this->info("{$user->email} is now {$role->value}.");

        return self::SUCCESS;
    }

    private function act(UserStateMachine $machine, string $action): int
    {
        $user = $this->findUser();
        if ($user === null) {
            return self::FAILURE;
        }

        match ($action) {
            'reject' => $machine->reject($user, $this->actor(), (bool) $this->option('block')),
            'suspend' => $machine->suspend($user, $this->actor()),
            'reactivate' => $machine->reactivate($user, $this->actor()),
        };

        $this->info(ucfirst($action)."d {$user->email}.");

        return self::SUCCESS;
    }

    private function findUser(): ?User
    {
        $email = $this->argument('email') ?: $this->ask('Email address');
        $user = User::where('email', strtolower(trim((string) $email)))->first();

        if ($user === null) {
            $this->error("No user found with email {$email}.");
            $this->line('  Run "worktrack:user list" to see who exists.');
        }

        return $user;
    }

    private function role(): ?UserRole
    {
        $role = UserRole::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->error('Role must be one of: admin, manager, member, viewer.');
        }

        return $role;
    }

    /** Shell actions have no acting user; self-action guard rails do not apply. */
    private function actor(): ?User
    {
        return null;
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
