<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Services\Auth\Exceptions\AccountLinkBlockedException;
use App\Services\Auth\Exceptions\EmailNotVerifiedException;
use App\Services\Auth\Exceptions\SignupBlockedException;
use App\Services\Auth\Exceptions\SignupRateLimitedException;
use App\Services\Auth\GoogleIdentityResolver;
use App\Services\Auth\RememberMe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController
{
    public function redirect(Request $request, RememberMe $remember): RedirectResponse
    {
        // Google returns to exactly one registered URI. Starting the flow on any other
        // host parks `state` in a session cookie the callback host never receives, so
        // the round trip fails its CSRF check — but only once: the failure redirects to
        // route('login') on the callback host, so the second attempt runs entirely on
        // the right origin and succeeds. That is the "first login always fails, retry
        // works" report. Locally it bites whenever the app answers on both Herd
        // (worktrack.test) and `php artisan serve` (localhost:8000), which is the normal
        // state of this project. Sending the browser to the canonical origin BEFORE the
        // handoff makes the first attempt the successful one.
        if ($origin = $this->foreignOriginFor($request)) {
            return redirect()->away($origin.$request->getRequestUri());
        }

        // The checkbox lives on our page but the login happens in callback(), so the
        // choice is parked in the session across the round trip (FR-1.11).
        $remember->capture($request);

        // Identity scopes only. No offline access and no stored tokens — we
        // authenticate people, we never act on their behalf, so holding a refresh
        // token would be liability without purpose.
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request, GoogleIdentityResolver $resolver, RememberMe $remember): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            // The `state` parameter did not match the one parked in the session: the
            // sign-in page sat open past the session lifetime, the callback URL was
            // replayed, or the flow began on a host whose cookie never reached us
            // (redirect() now forecloses that last one). Benign and self-correcting, so
            // it is a warning rather than an error — but it is logged, because a spike
            // means the origin guard is misconfigured and every user is eating a
            // failed first attempt in silence.
            Log::warning('Google sign-in rejected: OAuth state mismatch.', [
                'host' => $request->getHost(),
                'ip' => $request->ip(),
            ]);

            return redirect()->route('login')->withErrors([
                'google' => 'That sign-in expired or was already used. Please try again.',
            ]);
        } catch (\Throwable $e) {
            // A provider outage, revoked credentials, a malformed token response — or a
            // genuine bug in our own code. The user-facing message says nothing on
            // purpose, which is exactly why the class and message must be recorded:
            // without this the failure is invisible and indistinguishable from the
            // benign case above. The message is bounded because provider exceptions
            // embed response bodies. Credentials are POSTed in the token request body,
            // never in a URI, so no secret rides along here.
            Log::error('Google sign-in failed during the provider exchange.', [
                'exception' => $e::class,
                'message' => Str::limit($e->getMessage(), 300),
                'host' => $request->getHost(),
                'ip' => $request->ip(),
            ]);

            return redirect()->route('login')
                ->withErrors(['google' => 'That sign-in attempt could not be completed. Please try again.']);
        }

        try {
            $user = $resolver->resolve($googleUser, $request->ip(), $request->userAgent());
        } catch (EmailNotVerifiedException) {
            return redirect()->route('login')->withErrors([
                'google' => 'Your Google account email is not verified. Verify it with Google, then try again.',
            ]);
        } catch (AccountLinkBlockedException|SignupBlockedException) {
            // Deliberately identical and deliberately vague. Confirming "that address
            // already exists here" to an unauthenticated stranger is a disclosure, and
            // distinguishing blocked-from-link would be an oracle.
            return redirect()->route('login')->withErrors([
                'google' => 'That account cannot be used to sign in. Contact your administrator.',
            ]);
        } catch (SignupRateLimitedException) {
            return redirect()->route('login')->withErrors([
                'google' => 'Too many sign-up attempts from this network. Please try again later.',
            ]);
        }

        // FR-1.11. Remember-me is safe here only because SessionRevoker cycles
        // `remember_token`, so a suspension invalidates outstanding recaller cookies
        // as well as sessions — the concern that originally disabled it (AUTH-D10).
        $remember->login($user, $remember->consume($request));

        $request->session()->regenerate();   // fixation defence on every login

        return $user->status === UserStatus::Active
            ? redirect()->intended(route('board'))
            : redirect()->route('auth.pending');
    }

    /**
     * The canonical OAuth origin, but only when this request did not already arrive on
     * it. Null means there is nothing to correct.
     *
     * Compared on HOST alone, deliberately. Session cookies are scoped by host and
     * ignore both scheme and port, so host is exactly the granularity at which the
     * cookie is lost — and it is also the only granularity that is safe: comparing
     * scheme would fire this guard behind every TLS-terminating proxy, where the app
     * legitimately sees http while the world sees https.
     *
     * A missing or hostless redirect URI disables the guard rather than guessing. The
     * destination is built from config, never from input, so this cannot be steered
     * into an open redirect.
     */
    private function foreignOriginFor(Request $request): ?string
    {
        $configured = config('services.google.redirect');
        $host = is_string($configured) ? parse_url($configured, PHP_URL_HOST) : null;

        if ($host === null || $host === $request->getHost()) {
            return null;
        }

        $scheme = parse_url($configured, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($configured, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
