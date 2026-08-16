<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\Exceptions\IllegalStateTransitionException;
use App\Services\Auth\UserStateMachine;
use Illuminate\Support\Facades\DB;

describe('the approval screen is admin-only', function () {

    it('lets an admin open it and actually renders the queue', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        makeUser('waiting@gmail.com', UserRole::Viewer, UserStatus::Pending);

        // Asserting content, not just a 200: a blank page with a broken Blade
        // partial still returns 200, and that is exactly the failure worth catching.
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Users')
            ->assertSee('waiting@gmail.com')
            ->assertSee('Approve')
            ->assertSee('pending');
    });

    it('refuses a manager, a member and a viewer', function (UserRole $role) {
        $this->actingAs(makeUser("{$role->value}@gmail.com", $role))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    })->with([UserRole::Manager, UserRole::Member, UserRole::Viewer]);

    it('refuses a pending user before the capability check even matters', function () {
        $this->actingAs(makeUser('new@gmail.com', UserRole::Admin, UserStatus::Pending))
            ->get(route('admin.users.index'))
            ->assertRedirect(route('auth.pending'));
    });
});

describe('approving a signup', function () {

    it('activates the account with the chosen role and records who approved it', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $pending = makeUser('joy@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($admin)
            ->post(route('admin.users.approve', $pending), ['role' => 'member'])
            ->assertRedirect();

        $pending->refresh();

        expect($pending->status)->toBe(UserStatus::Active)
            ->and($pending->role)->toBe(UserRole::Member)
            ->and($pending->approved_by_user_id)->toBe($admin->id)
            ->and($pending->approved_at)->not->toBeNull();
    });

    it('leaves the approved user with no trackers, so they still see nothing', function () {
        // FR-1.10 — approval grants an account, not visibility. Easy to assume
        // otherwise, and the reason the empty state exists.
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $pending = makeUser('joy@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($admin)->post(route('admin.users.approve', $pending), ['role' => 'member']);

        expect($pending->fresh()->trackerMemberships()->count())->toBe(0);
    });

    it('rejects with an optional block on future signups', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $pending = makeUser('spam@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($admin)
            ->post(route('admin.users.reject', $pending), ['block' => '1'])
            ->assertRedirect();

        expect($pending->fresh()->status)->toBe(UserStatus::Rejected)
            // FR-1.6 — otherwise they re-sign-up and refill the queue.
            ->and(DB::table('blocked_emails')->where('canonical_email', 'spam@gmail.com')->exists())->toBeTrue();
    });
});

describe('suspension takes effect immediately', function () {

    it('destroys the suspended user\'s sessions rather than waiting for next login', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $victim = makeUser('gone@gmail.com');

        // A live session row, as the database session driver would write.
        DB::table('sessions')->insert([
            'id' => 'test-session-id', 'user_id' => $victim->id,
            'ip_address' => '203.0.113.1', 'user_agent' => 'test',
            'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->post(route('admin.users.suspend', $victim));

        expect($victim->fresh()->status)->toBe(UserStatus::Suspended)
            ->and(DB::table('sessions')->where('user_id', $victim->id)->exists())->toBeFalse();
    })->skip(fn () => config('session.driver') !== 'database', 'session driver is not database in tests');

    it('flips status so the very next request is denied', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $victim = makeUser('gone@gmail.com');

        $this->actingAs($admin)->post(route('admin.users.suspend', $victim));

        $this->actingAs($victim->fresh())->get('/board')->assertRedirect(route('auth.pending'));
    });
});

describe('the lockout guard rails', function () {

    // These prevent the one unrecoverable mistake in the whole admin surface. The
    // escape hatch is break-glass, which costs a credential rotation every use — so
    // it must never be the routine remedy for an avoidable slip.
    it('refuses to let an admin change their own role', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        makeUser('second@example.com', UserRole::Admin);   // so it is not a last-admin refusal

        $this->actingAs($admin)
            ->post(route('admin.users.role', $admin), ['role' => 'viewer'])
            ->assertSessionHasErrors('user');

        expect($admin->fresh()->role)->toBe(UserRole::Admin);
    });

    it('refuses to let an admin suspend themselves', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        makeUser('second@example.com', UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $admin))
            ->assertSessionHasErrors('user');

        expect($admin->fresh()->status)->toBe(UserStatus::Active);
    });

    it('refuses to demote the LAST active admin', function () {
        $only = makeUser('only@example.com', UserRole::Admin);
        $other = makeUser('other@example.com', UserRole::Admin);

        // Demote the second one legitimately, leaving exactly one.
        app(UserStateMachine::class)->setRole($other, UserRole::Member, $only);

        // Now nothing may take the last one away — not even another admin, because
        // there is no other admin.
        expect(fn () => app(UserStateMachine::class)->setRole($only, UserRole::Viewer))
            ->toThrow(IllegalStateTransitionException::class);

        expect($only->fresh()->role)->toBe(UserRole::Admin);
    });

    it('refuses to touch the break-glass account through the web surface', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);

        $this->artisan('worktrack:break-glass create --email=bg@worktrack.local')->assertSuccessful();
        $bg = User::where('is_break_glass', true)->firstOrFail();

        // A compromised admin session must not be able to disable the recovery path.
        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $bg))
            ->assertSessionHasErrors('user');

        expect($bg->fresh()->status)->toBe(UserStatus::Active);
    });
});

describe('illegal status transitions', function () {

    it('refuses to approve someone who is already active', function () {
        $admin = makeUser('admin@example.com', UserRole::Admin);
        $active = makeUser('already@gmail.com');

        $this->actingAs($admin)
            ->post(route('admin.users.approve', $active), ['role' => 'member'])
            ->assertSessionHasErrors('user');
    });
});

describe('the first-admin bootstrap', function () {

    it('promotes the first pending signup when no admin exists', function () {
        makeUser('founder@example.com', UserRole::Viewer, UserStatus::Pending);

        $this->artisan('worktrack:user first-admin founder@example.com')->assertSuccessful();

        $user = User::where('email', 'founder@example.com')->firstOrFail();

        expect($user->role)->toBe(UserRole::Admin)
            ->and($user->status)->toBe(UserStatus::Active);
    });

    it('refuses once an admin already exists, so it cannot become an escalation path', function () {
        makeUser('admin@example.com', UserRole::Admin);
        makeUser('sneaky@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->artisan('worktrack:user first-admin sneaky@gmail.com')->assertFailed();

        expect(User::where('email', 'sneaky@gmail.com')->first()->role)->toBe(UserRole::Viewer);
    });
});
