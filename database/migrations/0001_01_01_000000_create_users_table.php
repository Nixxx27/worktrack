<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical users table.
 *
 * Reconciles three separate domain designs that each defined `users` with different
 * columns (docs/design/{auth-lifecycle,authorization,data-model}.json). Naming
 * collisions resolved per docs/ARCHITECTURE.md §9.2:
 *   auth_type / auth_provider  -> auth_provider
 *
 * Edited in place rather than added as a follow-up migration (AUTH-D26), because
 * `password` must be NULLABLE from the start: every SSO user has no password, and
 * only the single break-glass account has one.
 *
 * password_reset_tokens is deliberately NOT created (AUTH-D17). A reset path
 * through Gmail SMTP would defeat the very outage that break-glass exists to
 * survive. Rotation is `worktrack:break-glass:rotate`, which requires shell access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Identity. google_id is the Google `sub` claim and is the ONLY key used
            // for returning-user lookups (AUTH-D1). Email is mutable display data and
            // must never be treated as an identity key.
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url', 512)->nullable();

            // Authentication method. Mutually exclusive per account (AUTHZ-15).
            $table->enum('auth_provider', ['google', 'local'])->default('google');
            $table->string('password')->nullable();          // local/break-glass only
            $table->boolean('is_break_glass')->default(false);

            // Two-layer authorization: global role is the ceiling, tracker membership
            // is visibility (docs/ARCHITECTURE.md §4).
            $table->enum('role', ['admin', 'manager', 'member', 'viewer'])->default('viewer');
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected', 'blocked'])
                ->default('pending');

            $table->string('timezone', 64)->default('Asia/Manila');

            // Break-glass operational state (AUTH-D14, AUTH-D18).
            // NOTE: break_glass_locked_until must gate FAILED attempts only. See
            // VERIFICATION.md auth-lifecycle-1 — as originally designed, an attacker
            // could hold the lock indefinitely and lock the owner out of the very
            // emergency path the outage requires. Enforcement is in the authenticator.
            $table->dateTime('break_glass_locked_until')->nullable();
            $table->dateTime('break_glass_last_used_at')->nullable();
            $table->boolean('break_glass_rotation_required')->default(false);

            // Approval lifecycle (FR-1.5).
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_login_at')->nullable();

            $table->rememberToken();   // retained for framework compatibility; no cookie is ever issued (AUTH-D10)
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_users_approval_queue');   // FR-1.5 admin screen
            $table->index(['role', 'status'], 'idx_users_role');                   // admin fan-out for notifications
        });

        // Exactly ONE break-glass account may exist. MySQL has no partial indexes, so
        // a generated column that is NULL for every non-break-glass row gives real
        // partial uniqueness (DD-15) — multiple NULLs are not duplicates.
        // Without this, a bug or a compromised admin session could create a second
        // password-bearing admin, which is a security failure rather than a data-quality one.
        DB::statement('
            ALTER TABLE users
            ADD COLUMN break_glass_singleton TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(is_break_glass = 1, 1, NULL)) STORED,
            ADD UNIQUE KEY uk_users_break_glass_singleton (break_glass_singleton)
        ');

        // A password may only exist on a local account, and a local account must have one.
        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT chk_users_auth_provider
            CHECK (
                (auth_provider = 'local'  AND password IS NOT NULL AND google_id IS NULL)
             OR (auth_provider = 'google' AND password IS NULL)
            )
        ");

        // Only a local account can be the break-glass account.
        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT chk_users_break_glass_is_local
            CHECK (is_break_glass = 0 OR auth_provider = 'local')
        ");

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
