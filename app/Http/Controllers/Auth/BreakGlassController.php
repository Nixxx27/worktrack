<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\AuditLogger;
use App\Services\Auth\BreakGlassAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Lives at its own URL, unlinked from the normal sign-in page (AUTH-D12).
 *
 * There is no password-reset broker anywhere in this application: a reset delivered
 * over Gmail SMTP would depend on the very infrastructure this door exists to
 * survive without. Rotation is an artisan command, requiring shell access.
 */
class BreakGlassController
{
    public function show()
    {
        abort_unless(config('worktrack.break_glass.enabled'), 404);
        $this->assertIpAllowed(request());

        return view('auth.break-glass');
    }

    public function attempt(Request $request, BreakGlassAuthenticator $auth)
    {
        abort_unless(config('worktrack.break_glass.enabled'), 404);
        $this->assertIpAllowed($request);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $auth->attempt($data['email'], $data['password'], $request->ip());

        if ($user === null) {
            // ONE message for every failure mode. Nothing here distinguishes wrong
            // password from no-such-account from throttled.
            throw ValidationException::withMessages([
                'email' => 'Those credentials could not be used to sign in.',
            ]);
        }

        // Never remembered, and this is the one login where that stays true. FR-1.11
        // exists to spare people a daily click; the emergency account is used roughly
        // never, costs a credential rotation each time (NFR-S8), and a long-lived
        // cookie on the one credential that bypasses Google is the opposite of the
        // trade this door was built for.
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return redirect()->route('board')->with('status', 'break-glass-used');
    }

    /**
     * Optional allowlist. Denies with 404 rather than 403, so an unlisted address
     * learns nothing about whether this route exists at all.
     */
    private function assertIpAllowed(Request $request): void
    {
        $allowed = config('worktrack.break_glass.ip_allowlist');

        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            app(AuditLogger::class)->breakGlassAttempt('ip_denied', null, $request->ip());
            abort(404);
        }
    }
}
