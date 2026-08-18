{{--
    FR-1.3 — a Pending user must see ONLY this. No trackers, no projects, no
    dashboard, no data endpoint. The global EnsureAccountActive middleware is what
    enforces that; this page is just the honest explanation of why.
--}}
<x-layouts.centered title="Waiting for approval · Worktrack">
    <div class="rounded-xl border border-line bg-surface p-6 shadow-sm">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-health-atrisk-bg px-2.5 py-1 font-mono text-[10px] uppercase tracking-[0.08em] text-health-atrisk">
            <span class="size-1.5 rounded-full bg-current"></span> Awaiting approval
        </span>

        <h1 class="mt-4 text-base font-semibold">Your account isn't active yet</h1>
        <p class="mt-2 text-sm text-ink-soft">
            You're signed in as <strong class="font-medium text-ink">{{ auth()->user()->email }}</strong>.
            An administrator has been notified and needs to approve you before you can
            see any projects.
        </p>
        <p class="mt-3 text-sm text-ink-soft">
            You'll get an email as soon as that happens. Nothing else to do in the meantime.
        </p>

        <x-sign-out-form class="mt-6">
            <button type="submit" class="text-sm font-medium text-ink-soft underline hover:text-ink">
                Sign out
            </button>
        </x-sign-out-form>
    </div>
</x-layouts.centered>
