<x-layouts.app title="Board · Worktrack">
    <div class="mx-auto max-w-3xl px-6 py-16">
        <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-slate-500">Signed in</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight">
            Hello, {{ auth()->user()->name }}
        </h1>
        <p class="mt-2 text-sm text-slate-600">
            Authentication, the approval gate and the tracker scoping layer are in place.
            The board itself is the next thing to build — steps, cards and drag-and-drop.
        </p>

        <dl class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ([
                'Role' => ucfirst(auth()->user()->role->value),
                'Status' => ucfirst(auth()->user()->status->value),
                'Trackers' => auth()->user()->trackerMemberships()->count(),
                'Sign-in' => ucfirst(auth()->user()->auth_provider->value),
            ] as $k => $v)
                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <dt class="font-mono text-[10px] uppercase tracking-[0.08em] text-slate-500">{{ $k }}</dt>
                    <dd class="mt-1 font-mono text-sm">{{ $v }}</dd>
                </div>
            @endforeach
        </dl>

        @if (auth()->user()->break_glass_rotation_required)
            <p class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-900">
                <strong class="font-semibold">Break-glass access was used.</strong>
                Rotate the password now with <code class="font-mono">php artisan worktrack:break-glass:rotate</code>.
                This notice stays until you do.
            </p>
        @endif

        <x-sign-out-form class="mt-8">
            <button class="text-sm font-medium text-slate-600 underline hover:text-slate-900">Sign out</button>
        </x-sign-out-form>
    </div>
</x-layouts.app>
