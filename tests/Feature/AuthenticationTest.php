<?php

use App\Enums\AuthProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Auth\BreakGlassAuthenticator;
use App\Services\Auth\EmailCanonicalizer;
use App\Services\Auth\UserStateMachine;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

describe('the approval gate', function () {

    it('sends a pending user to the waiting screen from any protected route', function () {
        $pending = makeUser('new@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($pending)->get('/board')->assertRedirect(route('auth.pending'));
    });

    it('lets an approved user reach the board', function () {
        $active = makeUser('ok@gmail.com');

        $this->actingAs($active)->get('/board')->assertOk();
    });

    it('denies a suspended user on their very next request, not at next login', function () {
        // FR-1.7 — the whole point is that no re-login is required for revocation to
        // bite. Status is read from the DB row on every request.
        $user = makeUser('gone@gmail.com');

        $this->actingAs($user)->get('/board')->assertOk();

        $user->update(['status' => UserStatus::Suspended]);

        $this->actingAs($user->fresh())->get('/board')->assertRedirect(route('auth.pending'));
    });

    it('answers data requests with 403 rather than an HTML redirect', function () {
        $pending = makeUser('api@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($pending)
            ->getJson('/board')
            ->assertForbidden();
    });

    it('lets a pending user reach the waiting screen and sign out', function () {
        $pending = makeUser('wait@gmail.com', UserRole::Viewer, UserStatus::Pending);

        $this->actingAs($pending)->get('/pending')->assertOk();
        $this->actingAs($pending)->post('/logout')->assertRedirect(route('login'));
    });
});

describe('email canonicalisation for the blocklist', function () {

    // AUTH-D21 — without this, a rejected applicant re-applies as
    // first.last+2@gmail.com and refills the approval queue indefinitely.
    it('folds gmail dots and tags but leaves other domains alone', function () {
        $c = new EmailCanonicalizer;

        expect($c->canonicalize('First.Last+worktrack@Gmail.com'))->toBe('firstlast@gmail.com')
            ->and($c->canonicalize('first.last@googlemail.com'))->toBe('firstlast@gmail.com')
            // Dots are significant almost everywhere else — folding them universally
            // would collide genuinely different people.
            ->and($c->canonicalize('First.Last@example.com'))->toBe('first.last@example.com');
    });
});

describe('break-glass authentication', function () {

    beforeEach(function () {
        RateLimiter::clear('break-glass-global');

        $this->password = 'correct-horse-battery-staple-1234';
        $this->bg = User::create([
            'name' => 'Break-glass Administrator',
            'email' => 'break-glass@worktrack.local',
            'email_verified_at' => now(),
            'auth_provider' => AuthProvider::Local,
            'password' => $this->password,
            'is_break_glass' => true,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Manila',
        ]);
    });

    it('authenticates with the correct password and forces rotation afterwards', function () {
        $user = app(BreakGlassAuthenticator::class)
            ->attempt($this->bg->email, $this->password, '203.0.113.10');

        expect($user)->not->toBeNull()
            // NFR-S8 — every use makes the credential expensive, which is what keeps
            // this from becoming a convenient everyday admin login.
            ->and($user->fresh()->break_glass_rotation_required)->toBeTrue()
            ->and($user->fresh()->break_glass_last_used_at)->not->toBeNull();
    });

    it('refuses a wrong password and never records the submitted secret', function () {
        $submitted = 'hunter2-not-the-real-password';

        $result = app(BreakGlassAuthenticator::class)
            ->attempt($this->bg->email, $submitted, '203.0.113.10');

        expect($result)->toBeNull();

        $entry = DB::table('audit_logs')->where('action', 'auth.break_glass.attempt')->first();

        expect($entry)->not->toBeNull()
            // A reason code, so an incident can be reconstructed...
            ->and($entry->context)->toContain('wrong_password')
            // ...but never the secret itself, in any column, in any form.
            ->and($entry->context)->not->toContain($submitted)
            ->and(json_encode((array) $entry))->not->toContain($submitted);
    });

    it('never authenticates a Google account through this path', function () {
        // The SQL narrows to is_break_glass + local provider, so even a Google row that
        // somehow carried a hash cannot be authenticated here.
        $google = makeUser('member@gmail.com');

        $result = app(BreakGlassAuthenticator::class)
            ->attempt($google->email, 'anything', '203.0.113.10');

        expect($result)->toBeNull();
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // REGRESSION TEST — VERIFICATION.md auth-lifecycle-1.
    //
    // The original design consulted a global rate limiter and a persistent row lock
    // BEFORE checking the password, justified because "legitimate use is roughly zero
    // per year". That property was the vulnerability: an attacker only had to keep the
    // counter saturated to hold the emergency door shut — during precisely the Google
    // outage the door exists for.
    //
    // The invariant these tests pin: a throttle must never deny a request that
    // presents valid credentials.
    // ═══════════════════════════════════════════════════════════════════════════
    it('still admits the owner while an attacker is flooding from other IPs', function () {
        // Attacker burns the global counter well past the alert threshold.
        for ($i = 0; $i < 40; $i++) {
            app(BreakGlassAuthenticator::class)
                ->attempt($this->bg->email, 'guess-'.$i, '198.51.100.'.($i % 255));
        }

        // The owner, on a different IP, with the correct password.
        $user = app(BreakGlassAuthenticator::class)
            ->attempt($this->bg->email, $this->password, '203.0.113.77');

        expect($user)->not->toBeNull();
    });

    it('still admits the owner after a stale lockout was left on the row', function () {
        $this->bg->update(['break_glass_locked_until' => now()->addHour()]);

        $user = app(BreakGlassAuthenticator::class)
            ->attempt($this->bg->email, $this->password, '203.0.113.77');

        // A correct password clears the lock rather than being blocked by it: the lock
        // exists to slow guessing, and a correct guess is not guessing.
        expect($user)->not->toBeNull()
            ->and($user->fresh()->break_glass_locked_until)->toBeNull();
    });

    it('does block repeated failures from the SAME ip', function () {
        // Kept as a hard block: a legitimate operator retries from one address, and an
        // attacker distributing across IPs cannot use this to lock anyone out.
        for ($i = 0; $i < 3; $i++) {
            app(BreakGlassAuthenticator::class)
                ->attempt($this->bg->email, 'nope', '198.51.100.5');
        }

        $blocked = app(BreakGlassAuthenticator::class)
            ->attempt($this->bg->email, $this->password, '198.51.100.5');

        expect($blocked)->toBeNull();
    });
});

describe('break-glass provisioning', function () {

    it('generates its own password and refuses to create a second account', function () {
        $this->artisan('worktrack:break-glass create --email=bg@worktrack.local')
            ->assertSuccessful();

        expect(User::where('is_break_glass', true)->count())->toBe(1);

        // The DB enforces this too, via partial uniqueness — the command just fails
        // more legibly than errno 1062.
        $this->artisan('worktrack:break-glass create --email=second@worktrack.local')
            ->assertFailed();

        expect(User::where('is_break_glass', true)->count())->toBe(1);
    });

    it('clears the rotation banner when rotated', function () {
        $this->artisan('worktrack:break-glass create --email=bg@worktrack.local')->assertSuccessful();

        User::where('is_break_glass', true)->update(['break_glass_rotation_required' => true]);

        $this->artisan('worktrack:break-glass rotate')->assertSuccessful();

        expect(User::where('is_break_glass', true)->first()->break_glass_rotation_required)->toBeFalse();
    });
});

describe('remember me', function () {

    /**
     * Build a recaller cookie exactly as SessionGuard would, so these tests exercise
     * the real re-authentication path rather than a paraphrase of it.
     */
    function recaller(User $user, string $token): array
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return [
            $guard->getRecallerName(),
            // Third segment is the HMAC of the password hash. SSO users have no
            // password at all, and the recaller must still be valid for them —
            // which is the case that matters here, since every real user is SSO.
            $user->id.'|'.$token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword() ?? ''),
        ];
    }

    function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /** The recaller cookie queued onto a response, or null if none was issued. */
    function issuedRecaller(TestResponse $response)
    {
        return collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === recallerName());
    }

    /** Drive the real Google callback with a stubbed provider response. */
    function signInWithGoogle(array $query = []): TestResponse
    {
        $socialite = new SocialiteUser;
        $socialite->map([
            'id' => 'google-sub-12345',
            'name' => 'Remembered Person',
            'email' => 'remembered@gmail.com',
            'avatar' => null,
        ]);
        $socialite->setRaw(['email_verified' => true]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialite);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        // The redirect leg is where the checkbox is read, so it must be part of the
        // flow — the session it writes is what the callback reads back.
        test()->get(route('auth.google.redirect', $query));

        return test()->get(route('auth.google.callback'));
    }

    it('offers the checkbox on the sign-in page, ticked by default', function () {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Keep me signed in')
            ->assertSee('name="remember"', escape: false)
            ->assertSee('checked', escape: false);
    });

    it('issues a bounded recaller cookie when the box is ticked', function () {
        $cookie = issuedRecaller(signInWithGoogle(['remember' => 1]));

        expect($cookie)->not->toBeNull()
            // Laravel's own default is 400 days; we deliberately bound it tighter.
            ->and($cookie->getExpiresTime())->toBeLessThan(now()->addDays(31)->timestamp)
            ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp);
    });

    it('issues no recaller cookie when the box is left unticked', function () {
        // An unticked HTML checkbox submits nothing at all, so this is the real shape
        // of the request, not a synthetic "remember=0".
        expect(issuedRecaller(signInWithGoogle()))->toBeNull();
    });

    it('never remembers the break-glass account', function () {
        // NFR-S8 — the one credential that bypasses Google does not get a long-lived
        // cookie, however convenient that would be.
        RateLimiter::clear('break-glass-global');

        $password = 'correct-horse-battery-staple-1234';
        User::create([
            'name' => 'Break-glass Administrator',
            'email' => 'bg-remember@worktrack.local',
            'email_verified_at' => now(),
            'auth_provider' => AuthProvider::Local,
            'password' => $password,
            'is_break_glass' => true,
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Manila',
        ]);

        $response = $this->post('/break-glass', [
            'email' => 'bg-remember@worktrack.local',
            'password' => $password,
        ]);

        expect(issuedRecaller($response))->toBeNull();
    });
});

describe('revocation reaches remember-me cookies', function () {

    // ═══════════════════════════════════════════════════════════════════════════
    // REGRESSION TESTS — the reason AUTH-D10 disabled remember-me in the first place.
    //
    // A recaller cookie re-authenticates through the users table, never through
    // `sessions`, so deleting a user's sessions cannot reach it. Suspending someone
    // who had ticked "keep me signed in" would revoke their session and leave the
    // cookie that silently re-admits them.
    //
    // The invariant these pin: revoking a user's access invalidates EVERY credential
    // they hold, not just the one stored server-side.
    // ═══════════════════════════════════════════════════════════════════════════

    it('cycles the remember token when a user is suspended', function () {
        $admin = makeUser('boss@gmail.com', UserRole::Admin);
        $user = makeUser('suspended@gmail.com');
        $user->forceFill(['remember_token' => 'a-token-on-a-laptop-somewhere'])->save();

        app(UserStateMachine::class)->suspend($user, $admin);

        expect($user->fresh()->remember_token)
            ->not->toBe('a-token-on-a-laptop-somewhere')
            // Random rather than null: retrieveByToken() refuses an empty stored token
            // today, but leaving the column populated means it never depends on that.
            ->not->toBeEmpty();
    });

    it('cycles the remember token when a user is rejected', function () {
        $admin = makeUser('boss2@gmail.com', UserRole::Admin);
        $user = makeUser('rejected@gmail.com', UserRole::Viewer, UserStatus::Pending);
        $user->forceFill(['remember_token' => 'pending-user-token'])->save();

        app(UserStateMachine::class)->reject($user, $admin);

        expect($user->fresh()->remember_token)->not->toBe('pending-user-token');
    });

    it('stops the cycled token authenticating through the provider', function () {
        // This is the exact call a recaller cookie makes on the way in.
        $admin = makeUser('boss3@gmail.com', UserRole::Admin);
        $user = makeUser('provider@gmail.com');
        $user->forceFill(['remember_token' => 'valid-until-suspension'])->save();

        $provider = Auth::createUserProvider('users');

        expect($provider->retrieveByToken($user->id, 'valid-until-suspension'))->not->toBeNull();

        app(UserStateMachine::class)->suspend($user, $admin);

        expect($provider->retrieveByToken($user->id, 'valid-until-suspension'))->toBeNull();
    });

    it('refuses a real recaller cookie belonging to a suspended user', function () {
        $admin = makeUser('boss4@gmail.com', UserRole::Admin);
        $user = makeUser('cookie@gmail.com');
        $user->forceFill(['remember_token' => 'cookie-token-60-chars'])->save();

        [$name, $value] = recaller($user->fresh(), 'cookie-token-60-chars');

        resetContext();
        $this->withCookie($name, $value)->get('/board')->assertOk();

        app(UserStateMachine::class)->suspend($user->fresh(), $admin);

        // Same cookie, same browser — but a genuinely new request. The test client
        // keeps one app instance alive, so without these two the second call would
        // reuse the session and the guard's memoised user from the first and prove
        // nothing about the cookie.
        resetContext();
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCookie($name, $value)->get('/board')->assertRedirect(route('login'));
    });

    it('denies a suspended user even if a recaller cookie were to survive', function () {
        // Defence in depth: EnsureAccountActive reads status from the row on every
        // request, so the gate holds independently of the token cycling above.
        $user = makeUser('belt@gmail.com');
        $user->forceFill(['remember_token' => 'still-valid-token'])->save();
        [$name, $value] = recaller($user->fresh(), 'still-valid-token');

        // Suspend WITHOUT going through the state machine, so the token is untouched.
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        resetContext();
        $this->withCookie($name, $value)->get('/board')->assertRedirect(route('auth.pending'));
    });
});

describe('there is no password reset path', function () {

    it('does not create a password_reset_tokens table', function () {
        // AUTH-D17 — a reset over Gmail SMTP would depend on the very infrastructure
        // break-glass exists to survive without.
        expect(Schema::hasTable('password_reset_tokens'))->toBeFalse();
    });
});
