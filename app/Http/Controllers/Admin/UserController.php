<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\Exceptions\IllegalStateTransitionException;
use App\Services\Auth\UserStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-1.5 — the admin approval queue and user directory.
 *
 * This is the ONE unscoped user-listing surface in the application (AUTHZ-19). Every
 * other place a person is chosen — assignee, watcher, @mention — is served by a
 * tracker-scoped endpoint returning members only, so this screen is the single place
 * the whole directory is visible, and it is admin-gated at the route.
 */
class UserController
{
    public function index(Request $request)
    {
        $filter = $request->string('status')->toString();

        $users = User::query()
            ->when(
                in_array($filter, ['pending', 'active', 'suspended', 'rejected', 'blocked'], true),
                fn ($q) => $q->where('status', $filter),
            )
            // Pending first: the queue is the reason to open this page.
            ->orderByRaw("FIELD(status,'pending','active','suspended','rejected','blocked')")
            ->orderBy('email')
            ->withCount('trackerMemberships')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filter' => $filter,
            'counts' => DB::table('users')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status'),
            'roles' => UserRole::cases(),
        ]);
    }

    public function approve(Request $request, User $user, UserStateMachine $machine): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:admin,manager,member,viewer'],
        ]);

        return $this->run(
            fn () => $machine->approve($user, UserRole::from($data['role']), $request->user()),
            "Approved {$user->email} as {$data['role']}.",
        );
    }

    public function reject(Request $request, User $user, UserStateMachine $machine): RedirectResponse
    {
        return $this->run(
            fn () => $machine->reject($user, $request->user(), $request->boolean('block')),
            $request->boolean('block')
                ? "Rejected {$user->email} and blocked future sign-ups from that address."
                : "Rejected {$user->email}.",
        );
    }

    public function suspend(Request $request, User $user, UserStateMachine $machine): RedirectResponse
    {
        return $this->run(
            fn () => $machine->suspend($user, $request->user()),
            "Suspended {$user->email}. Their sessions were ended immediately.",
        );
    }

    public function reactivate(Request $request, User $user, UserStateMachine $machine): RedirectResponse
    {
        return $this->run(
            fn () => $machine->reactivate($user, $request->user()),
            "Reactivated {$user->email}.",
        );
    }

    public function setRole(Request $request, User $user, UserStateMachine $machine): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'in:admin,manager,member,viewer'],
        ]);

        return $this->run(
            fn () => $machine->setRole($user, UserRole::from($data['role']), $request->user()),
            "{$user->email} is now {$data['role']}.",
        );
    }

    /**
     * Guard-rail refusals carry messages written for an admin to read — they say what
     * was refused and what to do instead — so they surface as-is rather than as a 500.
     */
    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (IllegalStateTransitionException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('status', $success);
    }
}
