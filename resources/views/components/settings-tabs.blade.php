{{--
    Sub-navigation inside Settings. Trackers and Users are two halves of one job —
    configuring the system — so they are tabs within a section rather than two
    unrelated top-level destinations.

    Each tab is gated on the capability its route is gated on, so someone who can
    manage users but not create trackers simply sees one tab.
--}}
@props(['current' => null])   {{-- trackers | users --}}

@php
    $user = auth()->user();

    $tabs = array_values(array_filter([
        $user?->can('tracker.create')
            ? ['key' => 'trackers', 'label' => 'Trackers', 'url' => route('admin.trackers.index')]
            : null,
        $user?->can('users.manage')
            ? ['key' => 'users', 'label' => 'Users', 'url' => route('admin.users.index')]
            : null,
    ]));
@endphp

@if (count($tabs) > 1)
    <nav class="mt-5 flex gap-1 border-b border-line" aria-label="Settings sections">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['url'] }}"
               @class([
                   '-mb-px border-b-2 px-3 py-2 text-sm transition',
                   'border-royal-900 font-medium text-ink' => $tab['key'] === $current,
                   'border-transparent text-ink-soft hover:border-line-strong hover:text-ink' => $tab['key'] !== $current,
               ])
               @if ($tab['key'] === $current) aria-current="page" @endif>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
@endif
