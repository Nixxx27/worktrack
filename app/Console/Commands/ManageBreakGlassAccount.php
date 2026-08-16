<?php

namespace App\Console\Commands;

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions and rotates the single break-glass account (AUTH-D16, AUTH-D17).
 *
 * The command GENERATES the password itself and prints it once. There is
 * deliberately no --password flag: a password passed on the command line lands in
 * shell history and in the process list, which for the most privileged credential in
 * the system is not an acceptable default. Automation can pipe one in via stdin.
 *
 * There is no seeder for this account, on purpose. A seeded break-glass credential
 * is a known credential, and it would be identical across every environment.
 */
class ManageBreakGlassAccount extends Command
{
    protected $signature = 'worktrack:break-glass
                            {action : create or rotate}
                            {--email= : email for the account (create only)}
                            {--password-from-stdin : read the password from stdin instead of generating one}';

    protected $description = 'Create or rotate the break-glass administrator account';

    public function handle(AuditLogger $audit): int
    {
        return match ($this->argument('action')) {
            'create' => $this->create($audit),
            'rotate' => $this->rotate($audit),
            default => $this->fail('Action must be "create" or "rotate".'),
        };
    }

    private function create(AuditLogger $audit): int
    {
        // The DB enforces this too, via a unique index on a generated column — but a
        // clear message beats errno 1062.
        if (User::where('is_break_glass', true)->exists()) {
            $this->error('A break-glass account already exists. Use "rotate" to change its password.');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: $this->ask('Email for the break-glass account');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            // Accepting this would create an account that can never authenticate via
            // Google either, because linking is blocked by design.
            $this->error('A user already exists with that email address.');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        $user = User::create([
            'name' => 'Break-glass Administrator',
            'email' => $email,
            'email_verified_at' => now(),
            'auth_provider' => AuthProvider::Local,
            'password' => $password,
            'is_break_glass' => true,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'timezone' => config('worktrack.default_timezone'),
            'approved_at' => now(),
        ]);

        $audit->log('auth.break_glass.provisioned', $user->id, ['email' => $email]);

        $this->printCredential($email, $password);

        $this->newLine();
        $this->line('  Reminder: this is the only password-authenticated account in the system,');
        $this->line('  and the only admin path not covered by Google\'s MFA. Provision a second');
        $this->line('  human admin via SSO so this is not your only recovery route.');

        return self::SUCCESS;
    }

    private function rotate(AuditLogger $audit): int
    {
        $user = User::where('is_break_glass', true)->first();

        if ($user === null) {
            $this->error('No break-glass account exists yet. Run "create" first.');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        $user->forceFill([
            'password' => $password,
            'break_glass_rotation_required' => false,   // clears the admin banner
            'break_glass_locked_until' => null,
        ])->save();

        $audit->log('auth.break_glass.rotated', $user->id);

        $this->printCredential($user->email, $password);

        return self::SUCCESS;
    }

    private function resolvePassword(): string
    {
        if ($this->option('password-from-stdin')) {
            $password = trim((string) fgets(STDIN));

            if (strlen($password) < 20) {
                $this->error('Password from stdin must be at least 20 characters.');
                exit(self::FAILURE);
            }

            return $password;
        }

        // Generated, not chosen. A human-chosen password on this account is the
        // weakest link in a system whose every other login is federated.
        return Str::password(32, symbols: false);
    }

    private function printCredential(string $email, string $password): void
    {
        $this->newLine();
        $this->line('  <bg=yellow;fg=black> SHOWN ONCE — STORE IT IN A PASSWORD MANAGER NOW </>');
        $this->newLine();
        $this->line('  URL       /break-glass');
        $this->line("  Email     {$email}");
        $this->line("  Password  {$password}");
        $this->newLine();
    }
}
