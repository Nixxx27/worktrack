{{--
    FR-1.8 — the emergency door. Deliberately not linked from the sign-in page.
    Every attempt against it is written to the audit log synchronously, and every
    successful use forces a credential rotation.
--}}
<x-layouts.centered title="Administrator access · Worktrack">
    <div class="rounded-xl border border-line-strong bg-surface p-6 shadow-sm">
        <h1 class="text-base font-semibold">Administrator access</h1>
        <p class="mt-1 text-sm text-ink-soft">
            For use when Google sign-in is unavailable. Every attempt is logged, and
            using this account requires rotating its password afterwards.
        </p>

        <form method="POST" action="{{ route('break-glass.attempt') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium">Email</label>
                <input id="email" name="email" type="email" required autocomplete="off"
                       class="mt-1 w-full rounded-lg border border-line-strong px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-700">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1 w-full rounded-lg border border-line-strong px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal-700">
            </div>

            @error('email')
                <p class="rounded-lg bg-health-stalled-bg px-3 py-2 text-sm text-health-stalled">{{ $message }}</p>
            @enderror

            <button type="submit"
                    class="w-full rounded-lg bg-royal-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-royal-800">
                Sign in
            </button>
        </form>
    </div>
</x-layouts.centered>
