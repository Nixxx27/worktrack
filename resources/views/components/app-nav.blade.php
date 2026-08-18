{{--
    The one application header. Every signed-in screen renders this and nothing else,
    because five hand-copied navs is how the board ended up showing a role badge the
    dashboard didn't, and the admin screens ended up with no header at all.

    Layout, left to right: identity, then the control that scopes the page (tracker
    picker / filter, passed in as the slot), then destinations, then a rule, then the
    tools — settings and the account. Destinations are words; tools carry an icon, so
    "a place I go" and "a thing I operate" never look like the same kind of item.

    Activity is deliberately absent: it is reached from the footer line on every page,
    next to the work it records.

    The bar spans the viewport on every screen, including the ones whose content is
    contained. Pages disagree about content width — the board is a canvas and runs edge
    to edge, the dashboard is a document and stops at max-w-6xl — and when the header
    inherited that disagreement the wordmark and nav visibly jumped sideways on every
    navigation. Chrome that moves reads as a different application.
--}}
@props([
    'current' => null,     {{-- board | dashboard | settings --}}
    'eyebrow' => null,     {{-- the page name, e.g. "Dashboard" --}}
])

@php
    // The Settings destination is resolved from the same capabilities the routes are
    // gated on, so the menu can never offer a page the route will then refuse.
    $user        = auth()->user();
    $canTrackers = (bool) $user?->can('tracker.create');
    $canUsers    = (bool) $user?->can('users.manage');
    $settingsUrl = $canTrackers ? route('admin.trackers.index')
                 : ($canUsers ? route('admin.users.index') : null);

    $destinations = [
        ['key' => 'board',     'label' => 'Board',     'url' => route('board')],
        ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => route('dashboard')],
    ];
@endphp

<header class="sticky top-0 z-20 flex-none bg-royal-900 text-ink-inverse shadow-sm">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-2.5">
        <a href="{{ route('board') }}" class="flex items-center gap-2.5">
            {{-- The clipboard head rather than the full mascot, for the same reason the favicon
                 uses it: at 28px the running figure is a smudge. The plate is bone-50 so the
                 mark keeps its contrast against the royal header, and matches the app icon. --}}
            <img src="{{ asset('images/worktrack-icon.png') }}" alt="" width="205" height="256"
                 class="size-7 flex-none rounded-lg bg-bone-50 object-contain p-0.5">
            <span class="flex items-baseline gap-2">
                <span class="font-semibold tracking-tight">Worktrack</span>
                @if ($eyebrow)
                    <span class="font-mono text-[10px] uppercase tracking-[0.12em] text-royal-300">{{ $eyebrow }}</span>
                @endif
            </span>
        </a>

        {{-- The page's scoping control, if it has one. Sits next to the identity rather
             than in the nav: it changes what you are looking at, not where you are. --}}
        {{ $slot }}

        <nav class="ml-auto flex items-center gap-1 text-sm" aria-label="Main">
            {{-- The current page is marked by a filled pill, not by removing the link:
                 "you are here" has to survive being read at a glance. --}}
            @foreach ($destinations as $item)
                @if ($item['key'] === $current)
                    <span class="rounded-lg bg-royal-800 px-2.5 py-1 font-medium" aria-current="page">{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['url'] }}" class="rounded-lg px-2.5 py-1 text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse">{{ $item['label'] }}</a>
                @endif
            @endforeach

            {{-- ── tools ────────────────────────────────────────────────────────
                 Separated by a rule so configuration and sign-out are not mistaken
                 for two more places to work. --}}
            <span class="mx-2 hidden h-4 w-px bg-royal-700 sm:block" aria-hidden="true"></span>

            @if ($settingsUrl)
                <a href="{{ $settingsUrl }}"
                   @class([
                       'flex items-center gap-1.5 rounded-lg px-2.5 py-1 transition',
                       'bg-royal-800 font-medium' => $current === 'settings',
                       'text-royal-200 hover:bg-royal-800 hover:text-ink-inverse' => $current !== 'settings',
                   ])
                   @if ($current === 'settings') aria-current="page" @endif>
                    <svg class="size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    Settings
                </a>
            @endif

            @if ($user)
                <span class="ml-1 hidden font-mono text-[10px] uppercase tracking-[0.08em] text-royal-300 sm:inline">
                    {{ $user->role->label() }}
                </span>

                <x-sign-out-form>
                    <button class="flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-royal-200 transition hover:bg-royal-800 hover:text-ink-inverse">
                        <svg class="size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        Sign out
                    </button>
                </x-sign-out-form>
            @endif
        </nav>
    </div>
</header>
