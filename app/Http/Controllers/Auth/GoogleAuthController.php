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
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController
{
    public function redirect(Request $request, RememberMe $remember): RedirectResponse
    {
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
        } catch (\Throwable) {
            // Covers an expired or replayed state parameter and any provider failure.
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

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
