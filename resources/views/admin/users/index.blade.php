{{--
    FR-1.5 — the approval queue and user directory.

    Pending sits first and is styled loudest, because that is the only part of this
    page that represents someone waiting on a human. Everything else is reference.
--}}
<x-layouts.app title="Settings · Users · Worktrack">
    <x-app-nav current="settings" eyebrow="Settings" />

    <div class="mx-auto max-w-6xl px-5 py-6">

        <h1 class="text-lg font-semibold tracking-tight">Settings</h1>

        <x-settings-tabs current="users" />

        <div class="mt-6">
            <h2 class="text-base font-semibold tracking-tight">Users</h2>
            <p class="mt-1 max-w-2xl text-sm text-ink-soft">
                Anyone can sign up with Google. Nobody sees a single project until you approve them here.
            </p>
        </div>

        @if (session('status'))
            <p class="mt-6 rounded-lg bg-health-ontrack-bg px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        @error('user')
            {{-- Guard-rail refusals are written to be read by a person: what was
                 refused, and what to do instead. --}}
            <p class="mt-6 rounded-lg bg-health-stalled-bg px-4 py-3 text-sm text-red-900">{{ $message }}</p>
        @enderror

        {{-- Status filter, with counts so the queue size is visible without clicking --}}
        <nav class="mt-7 flex flex-wrap gap-1.5">
            @php
                $tabs = ['' => 'All', 'pending' => 'Pending', 'active' => 'Active',
                         'suspended' => 'Suspended', 'rejected' => 'Rejected', 'blocked' => 'Blocked'];
            @endphp
            @foreach ($tabs as $key => $label)
                @php $n = $key === '' ? $counts->sum() : ($counts[$key] ?? 0); @endphp
                <a href="{{ route('admin.users.index', $key ? ['status' => $key] : []) }}"
                   @class([
                       'rounded-lg px-3 py-1.5 text-sm',
                       'bg-royal-900 text-white' => $filter === $key,
                       'bg-surface text-ink border border-line hover:border-line-strong' => $filter !== $key,
                   ])>
                    {{ $label }}
                    <span @class(['ml-1 font-mono text-[11px]', 'text-health-atrisk' => $key === 'pending' && $n > 0, 'opacity-60' => !($key === 'pending' && $n > 0)])>{{ $n }}</span>
                </a>
            @endforeach
        </nav>

        <div class="mt-5 overflow-hidden rounded-xl border border-line bg-surface shadow-sm">
            @forelse ($users as $user)
                @php
                    $isSelf = $user->id === auth()->id();
                    $pending = $user->status === \App\Enums\UserStatus::Pending;
                @endphp
                <div @class(['flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-line-soft p-4 last:border-b-0', 'bg-health-atrisk-bg/40' => $pending])>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                            @if ($isSelf)
                                <span class="rounded bg-canvas-sunken px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-ink-soft">you</span>
                            @endif
                            @if ($user->is_break_glass)
                                <span class="rounded bg-red-100 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wide text-health-stalled">break-glass</span>
                            @endif
                        </div>
                        <p class="truncate font-mono text-xs text-ink-faint">{{ $user->email }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        @php
                            $tone = match ($user->status->value) {
                                'pending' => 'bg-health-atrisk-bg text-health-atrisk',
                                'active' => 'bg-health-ontrack-bg text-health-ontrack',
                                'suspended' => 'bg-health-stalled-bg text-health-stalled',
                                default => 'bg-canvas-sunken text-ink',
                            };
                        @endphp
                        <span class="rounded-full px-2.5 py-1 font-mono text-[10px] uppercase tracking-[0.06em] {{ $tone }}">
                            {{ $user->status->value }}
                        </span>
                        <span class="font-mono text-xs text-ink-faint">{{ $user->role->value }}</span>
                        <span class="font-mono text-xs text-ink-faint">{{ $user->tracker_memberships_count }} trackers</span>
                    </div>

                    {{-- Actions. The server re-checks every one of these; hiding a
                         button is presentation, never the control. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($user->is_break_glass)
                            <span class="text-xs text-ink-faint">Managed by artisan only</span>

                        @elseif ($pending)
                            <form method="POST" action="{{ route('admin.users.approve', $user) }}" class="flex items-center gap-1.5">
                                @csrf
                                <select name="role" class="rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                    @foreach ($roles as $role)
                                        <option value="{{ $role->value }}" @selected($role->value === 'viewer')>{{ $role->label() }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded-lg bg-royal-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-royal-800">
                                    Approve
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.users.reject', $user) }}">
                                @csrf
                                <label class="mr-1.5 text-xs text-ink-soft">
                                    <input type="checkbox" name="block" value="1" class="align-middle"> block
                                </label>
                                <button class="rounded-lg border border-line-strong px-3 py-1.5 text-xs font-medium hover:bg-canvas">
                                    Reject
                                </button>
                            </form>

                        @else
                            @if (! $isSelf)
                                <form method="POST" action="{{ route('admin.users.role', $user) }}" class="flex items-center gap-1.5">
                                    @csrf
                                    <select name="role" onchange="this.form.requestSubmit()"
                                            class="rounded-lg border border-line-strong px-2 py-1.5 text-xs">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}" @selected($user->role === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    <noscript><button class="text-xs underline">Set</button></noscript>
                                </form>
                            @endif

                            @if ($user->status === \App\Enums\UserStatus::Active && ! $isSelf)
                                <form method="POST" action="{{ route('admin.users.suspend', $user) }}">
                                    @csrf
                                    <button class="rounded-lg border border-line-strong px-3 py-1.5 text-xs font-medium hover:bg-canvas">
                                        Suspend
                                    </button>
                                </form>
                            @elseif (in_array($user->status->value, ['suspended', 'rejected', 'blocked'], true))
                                <form method="POST" action="{{ route('admin.users.reactivate', $user) }}">
                                    @csrf
                                    <button class="rounded-lg bg-royal-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-royal-800">
                                        Reactivate
                                    </button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <p class="p-10 text-center text-sm text-ink-faint">No users match that filter.</p>
            @endforelse
        </div>

        <div class="mt-5">{{ $users->links() }}</div>

        <x-app-footer class="mt-6">
            <span class="max-w-2xl normal-case tracking-normal">
                Approved users start with no trackers, so they still see nothing until you add them to one.
                Suspending someone ends their sessions immediately rather than at next sign-in.
            </span>
        </x-app-footer>
    </div>
</x-layouts.app>
