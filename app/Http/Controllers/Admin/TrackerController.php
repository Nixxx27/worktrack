<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Trackers\TrackerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tracker administration: create, archive, and manage membership.
 *
 * Admin-gated at the route via `can:tracker.create`.
 *
 * The index used to wrap its queries in SystemContext::run() to guarantee D9
 * (an admin sees every tracker, including ones they are not a member of). That threw
 * on every request: SystemContext refuses to run from an execution carrying a user
 * session, which is exactly the case its docblock warns about — "someone adds a
 * plausible SystemContext::run() inside an admin controller action ... and it throws
 * a 500 in production". Nothing caught it because no test requested this page over
 * HTTP.
 *
 * The bypass was never needed. AccessContext::seesAllTrackers() is already true for
 * an active Admin, so the ordinary scoped query returns every tracker, and it does so
 * WITHOUT widening visibility for anyone else who might later reach this code.
 */
class TrackerController
{
    public function index()
    {
        // Unscoped-looking, but scoped: TrackerVisibilityScope applies and resolves to
        // "everything" for an admin (D9), so a tracker created by a different admin
        // stays manageable without a bypass.
        return view('admin.trackers.index', [
            'trackers' => Tracker::withCount(['projects', 'members'])
                ->with([
                    'steps' => fn ($q) => $q->whereNull('archived_at')->orderBy('position'),
                    'members.user:id,name,role',
                ])
                ->orderBy('archived_at')->orderBy('name')->get(),
            'approvedUsers' => User::where('status', UserStatus::Active)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, TrackerService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'stall_threshold_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $tracker = $service->create($data, $request->user());

        return back()->with('status',
            "Created \"{$tracker->name}\" with Backlog, New, In Progress and Done. Rename or reorder those in tracker settings."
        );
    }

    public function addMember(Request $request, Tracker $tracker, TrackerService $service): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        // D10 — chosen from already-approved users. There is deliberately no
        // invite-by-email path, so Google signup plus admin approval stays the single
        // entry point into the system.
        $user = User::findOrFail($data['user_id']);

        if (! $user->isActive()) {
            return back()->withErrors(['member' => 'Only approved users can be added to a tracker.']);
        }

        $service->addMember($tracker, $user, $request->user());

        return back()->with('status', "Added {$user->name} to {$tracker->name}.");
    }

    public function removeMember(Request $request, Tracker $tracker, User $user, TrackerService $service): RedirectResponse
    {
        $service->removeMember($tracker, $user, $request->user());

        // Said plainly because it is destructive in a way that is easy to overlook:
        // removal unassigns their work immediately, since you cannot hold work in a
        // tracker you can no longer see.
        return back()->with('status',
            "Removed {$user->name} from {$tracker->name}. Their project assignments and task assignments there were cleared."
        );
    }

    public function archive(Request $request, Tracker $tracker, TrackerService $service): RedirectResponse
    {
        $hidden = $service->archive($tracker, $request->user());

        return back()->with('status', $hidden > 0
            ? "Archived {$tracker->name}. {$hidden} unfinished project(s) are now hidden but their history is intact."
            : "Archived {$tracker->name}.");
    }
}
