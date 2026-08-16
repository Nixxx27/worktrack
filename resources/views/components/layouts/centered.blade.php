<x-layouts.app :title="$title ?? 'Worktrack'">
    {{-- The signed-out shell. A single soft royal wash behind the card gives these
         pages a horizon, so the sign-in screen does not read as an unstyled form. --}}
    <div class="relative flex min-h-full items-center justify-center px-4 py-16">
        <div aria-hidden="true"
             class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-linear-to-b from-powder-200 to-transparent"></div>

        <div class="relative w-full max-w-md">
            <div class="mb-8 text-center">
                <span class="mb-3 inline-flex size-10 items-center justify-center rounded-xl bg-royal-900 text-sm font-semibold text-ink-inverse">
                    W
                </span>
                <p class="text-lg font-semibold tracking-tight text-ink">Worktrack</p>
                <p class="mt-1 font-mono text-[11px] uppercase tracking-[0.12em] text-ink-faint">
                    {{ config('worktrack.org_label') }}
                </p>
            </div>
            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
