{{--
    The sign-out form and its confirmation, everywhere sign-out appears.

    In the header this button sits in the same row as Board, Dashboard and Settings and
    is styled like them, so the control that ends the session reads as one more place to
    go. An unguarded mis-click costs the login plus every deferred wire:model draft in
    the drawer — the comment box, the new-task row, the health reason.

    One component rather than three hand-copied forms because the wording is a promise
    about what is about to be lost, and a promise re-typed per page drifts. The same
    mistake as the five hand-copied navs the header replaced.

    ── why a native <dialog> ───────────────────────────────────────────────────
    Not Alpine, which is how the drawer's own prompts are built: Alpine arrives with
    Livewire's injected script, and /pending is a plain Route::view with no Livewire on
    it at all. An x-data modal there would render a button that does nothing.

    <dialog> also earns its place on the board, where the trigger lives inside a
    `sticky z-20` header. showModal() promotes to the browser's top layer, so the panel
    escapes that stacking context instead of fighting it, and Escape, the focus trap and
    returning focus to the button on close all come from the platform rather than from
    hand-rolled key handlers.

    ── two layers, and why ─────────────────────────────────────────────────────
    The onsubmit confirm() below is the BASELINE, not a leftover. It is the guard that
    needs no build step, and it exists because this app has already shipped a deploy
    that pushed Blade without rebuilding Vite: production served the new form tagged
    data-sign-out alongside a JS bundle that had never heard of it, nothing intercepted
    the submit, and sign-out silently went back to being instant.

    A guard that lives only in app.js is a guard a partial deploy can delete. So the
    HTML asks on its own, and app.js UPGRADES that to the real dialog by intercepting
    in the capture phase and stopping the event before the inline handler can see it —
    which is why you never get both prompts. Stale assets cost you the nicer dialog,
    never the question itself.
--}}
<form method="POST" action="{{ route('logout') }}" data-sign-out
      onsubmit="return confirm('Sign out of Worktrack? Anything you have typed and not saved will be lost.')"
      {{ $attributes }}>
    @csrf
    {{ $slot }}
</form>

@once
    {{-- Rendered once per page and driven by a delegated listener, so the header may
         re-render underneath it without the dialog losing its handlers. wire:ignore keeps
         Livewire's morph off markup that holds no Livewire state; it is an inert
         attribute on the two screens with no Livewire. --}}
    <dialog id="sign-out-dialog" wire:ignore
            aria-labelledby="sign-out-title"
            class="mx-auto mt-16 mb-auto max-h-[calc(100dvh-8rem)] w-[calc(100vw-2rem)] max-w-md overflow-y-auto rounded-2xl border-0 bg-surface p-0 shadow-xl backdrop:bg-royal-900/40">
        <div class="rounded-t-2xl bg-royal-900 px-5 py-3.5 text-ink-inverse">
            <h2 id="sign-out-title" class="text-base font-semibold tracking-tight">
                Sign out of Worktrack?
            </h2>
            {{-- Which account, because people sign in here from shared machines and
                 "am I about to drop the right session?" is the actual question.

                 Mono like the header eyebrows elsewhere, but NOT uppercased the way those
                 are: an address is a literal string people match character by character,
                 and DUMP@GMAIL.COM is not what they typed. break-all because the panel is
                 max-w-md and a long firstname.lastname@ address would otherwise push the
                 header wider than the dialog. --}}
            <p class="mt-0.5 font-mono text-[11px] tracking-[0.04em] break-all text-royal-300">
                {{ auth()->user()?->email }}
            </p>
        </div>

        <div class="px-5 py-4">
            <p class="text-sm leading-relaxed text-ink-soft">
                Anything you have typed but not saved goes with the session — a comment
                part-way written, a task you had not added yet, a health note. Work you
                already saved is untouched, and signing back in takes one click.
            </p>
        </div>

        <div class="flex items-center justify-end gap-2 rounded-b-2xl border-t border-line-soft bg-canvas px-5 py-3">
            {{-- First in the DOM, so the platform's initial focus lands on the harmless
                 option and a stray Enter does not do the thing this dialog exists to
                 slow down. --}}
            <button type="button" autofocus data-sign-out-cancel
                    class="rounded-lg px-3 py-1.5 text-sm font-medium text-ink-soft transition hover:bg-canvas-sunken hover:text-ink">
                Stay signed in
            </button>
            <button type="button" data-sign-out-confirm
                    class="rounded-lg bg-royal-900 px-4 py-1.5 text-sm font-medium text-ink-inverse transition hover:bg-royal-800">
                Sign out
            </button>
        </div>
    </dialog>
@endonce
