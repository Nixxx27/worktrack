<x-layouts.centered title="Sign in · Worktrack">
    <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
        <h1 class="text-base font-semibold">Sign in</h1>
        <p class="mt-1 text-sm text-ink-soft">
            Use your Google account. New accounts need an administrator to approve them
            before any work is visible.
        </p>

        @error('google')
            <p class="mt-4 rounded-lg bg-health-stalled-bg px-3 py-2 text-sm text-health-stalled">{{ $message }}</p>
        @enderror

        {{--
            A GET form rather than the plain link this used to be, so the "keep me
            signed in" choice reaches the redirect route as a query parameter and is
            parked in the session for the Google round trip (FR-1.11, RememberMe).

            No @csrf: this is a GET that starts an OAuth handshake and changes nothing,
            and Socialite's own `state` parameter is what protects the callback.
        --}}
        <form method="GET" action="{{ route('auth.google.redirect') }}" class="mt-5">
            <button type="submit"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-royal-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-royal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-700">
                Continue with Google
            </button>

            @if ($rememberMe->enabled())
                {{-- Whole-row label: the box itself is a 16px target, and this is the
                     one control on the page a user might want to change. --}}
                <label class="mt-4 flex cursor-pointer items-start gap-2 text-sm text-ink-soft">
                    <input name="remember" type="checkbox" value="1"
                           @checked($rememberMe->defaultChecked())
                           class="mt-0.5 size-4 rounded border-line-strong text-royal-700 focus:ring-royal-600">
                    <span>
                        Keep me signed in
                        <span class="block text-xs text-ink-soft">
                            For {{ $rememberDays }} days on this device. Leave this unticked on a shared computer.
                        </span>
                    </span>
                </label>
            @endif
        </form>
    </div>
</x-layouts.centered>
