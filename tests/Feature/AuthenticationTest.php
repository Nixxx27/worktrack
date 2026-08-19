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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
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

describe('the sign-out guard', function () {

    // In the header, sign-out sits in the same row as Board, Dashboard and Settings and
    // is styled like them, so the control that ends the session reads as one more place
    // to go. An unguarded mis-click costs the login plus every deferred wire:model draft
    // in the drawer — the comment box, the new-task row, the health reason.
    //
    // The forms are located and then inspected, rather than the page being searched for
    // a string, so re-inlining a form somewhere without the guards still fails here.
    //
    // BOTH layers are asserted on purpose. The inline confirm() is the one that survives
    // a deploy which pushes Blade without running `npm run build` — that deploy has
    // happened, and it turned sign-out back into a single click. If a later change moves
    // the guard wholly into app.js again, this fails rather than production doing so.
    it('guards every sign-out form in the HTML and hooks it for the dialog', function () {
        $screens = [
            '/board' => makeUser('nav@gmail.com'),
            '/pending' => makeUser('waiting@gmail.com', UserRole::Viewer, UserStatus::Pending),
        ];

        $missingHook = [];
        $missingBaseline = [];
        $forms = 0;

        foreach ($screens as $path => $user) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            preg_match_all('/<form\\b[^>]*>/i', $html, $matches);

            foreach ($matches[0] as $form) {
                if (! str_contains($form, route('logout'))) {
                    continue;
                }

                $forms++;

                if (! str_contains($form, 'data-sign-out')) {
                    $missingHook[] = $path;
                }

                // Build-independent, so it is the layer that cannot be deployed away.
                if (! str_contains($form, 'onsubmit="return confirm(')) {
                    $missingBaseline[] = $path;
                }
            }
        }

        expect($missingHook)->toBe([])
            ->and($missingBaseline)->toBe([])
            ->and($forms)->toBe(count($screens));
    });

    // The dialog is what the hook above opens. Asserting it separately means a page that
    // ships the form without the dialog — the combination that would submit unguarded —
    // is a failure rather than a half-pass.
    it('ships the dialog on every screen that offers sign-out, exactly once', function () {
        $screens = [
            '/board' => makeUser('dialog-nav@gmail.com'),
            '/pending' => makeUser('dialog-wait@gmail.com', UserRole::Viewer, UserStatus::Pending),
        ];

        foreach ($screens as $path => $user) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            expect(substr_count($html, 'id="sign-out-dialog"'))->toBe(1)
                ->and($html)->toContain('data-sign-out-confirm')
                ->and($html)->toContain('data-sign-out-cancel')
                // Labelled, so the dialog announces itself rather than opening mute.
                ->and($html)->toContain('aria-labelledby="sign-out-title"');
        }
    });

    // The wording is the promise about what is about to be lost, and the address answers
    // "which session am I dropping" on a shared machine. Both should take a deliberate
    // edit here as well as in the component.
    it('names the account and says that unsaved work goes with it', function () {
        $this->actingAs(makeUser('draft@gmail.com'))
            ->get('/board')
            ->assertSee('Anything you have typed but not saved goes with the session', false)
            ->assertSee('draft@gmail.com', false);
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

describe('the OAuth flow starts on the host Google returns to', function () {

    /*
     * Regression cover for the "first sign-in always fails, the retry always works"
     * report. Google returns to one registered host; beginning the flow on a different
     * one strands `state` in a cookie that host never sends, so the callback fails its
     * CSRF check exactly once and the resulting redirect deposits the browser on the
     * correct host, where attempt two then succeeds.
     */

    it('sends the browser to the callback host before handing off to Google', function () {
        config(['services.google.redirect' => 'http://localhost:8000/auth/google/callback']);

        $this->get('http://worktrack.test/auth/google/redirect?remember=1')
            ->assertRedirect('http://localhost:8000/auth/google/redirect?remember=1');
    });

    it('carries the remember-me choice across the correction', function () {
        // The checkbox is read on the redirect leg, so a guard that dropped the query
        // string would silently turn every ticked box into an unticked one.
        config(['services.google.redirect' => 'http://localhost:8000/auth/google/callback']);

        $this->get('http://worktrack.test/auth/google/redirect?remember=1')
            ->assertRedirect('http://localhost:8000/auth/google/redirect?remember=1');

        $this->get('http://worktrack.test/auth/google/redirect')
            ->assertRedirect('http://localhost:8000/auth/google/redirect');
    });

    it('hands off to Google untouched when the host already matches', function () {
        // Host-only comparison: the configured URI names a port the test client does
        // not use, and that must NOT be treated as a foreign origin — cookies ignore
        // ports, so a port mismatch is not a lost session.
        config(['services.google.redirect' => 'http://localhost:8000/auth/google/callback']);

        $this->get('http://localhost/auth/google/redirect')
            ->assertRedirectContains('accounts.google.com');
    });

    it('stays out of the way when no redirect URI is configured', function () {
        config(['services.google.redirect' => null]);

        $this->get('http://worktrack.test/auth/google/redirect')
            ->assertRedirectContains('accounts.google.com');
    });
});

describe('failed sign-ins are not silent', function () {

    /** Drive the callback with a provider that throws. */
    function failingCallback(Throwable $thrown): TestResponse
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow($thrown);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return test()->get(route('auth.google.callback'));
    }

    it('logs a state mismatch as a warning and says the sign-in expired', function () {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'state mismatch'));

        failingCallback(new InvalidStateException)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        expect(session('errors')->first('google'))->toContain('expired');
    });

    it('logs a provider failure as an error, recording the exception class', function () {
        // Without this the generic user-facing message is the ONLY trace a real outage
        // leaves — indistinguishable from a user who simply left the page open.
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message, $context) => $context['exception'] === RuntimeException::class
                && str_contains($context['message'], 'token endpoint refused'));

        failingCallback(new RuntimeException('token endpoint refused the exchange'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');
    });

    it('does not sign anyone in when the provider fails', function () {
        Log::shouldReceive('error')->once();

        failingCallback(new RuntimeException('boom'));

        expect(Auth::check())->toBeFalse();
    });
});
