<?php

namespace App\Policies;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Both authorization layers meet here.
 *
 * Visibility failures return denyAsNotFound() rather than a plain deny: a 403
 * confirms the resource exists, which is exactly the disclosure FR-2.9 forbids.
 * In practice route-model binding usually 404s first because it resolves through
 * the scope — this is the belt to that braces, for any path that loads a model
 * some other way.
 */
class TrackerPolicy
{
    public function view(User $user, Tracker $tracker): Response
    {
        return $user->isMemberOf($tracker)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return CapabilityMatrix::allows($user, Capability::CreateTracker);
    }

    public function update(User $user, Tracker $tracker): Response
    {
        if (! $user->isMemberOf($tracker)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::UpdateTracker)
            ? Response::allow()
            : Response::deny('Only an administrator can change a tracker.');
    }

    public function archive(User $user, Tracker $tracker): Response
    {
        if (! $user->isMemberOf($tracker)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::ArchiveTracker)
            ? Response::allow()
            : Response::deny('Only an administrator can archive a tracker.');
    }

    public function manageMembers(User $user, Tracker $tracker): Response
    {
        if (! $user->isMemberOf($tracker)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::ManageTrackerMembers)
            ? Response::allow()
            : Response::deny('Only an administrator can change who belongs to a tracker.');
    }

    public function configureSteps(User $user, Tracker $tracker): Response
    {
        if (! $user->isMemberOf($tracker)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::ConfigureSteps)
            ? Response::allow()
            : Response::deny('Only an administrator can configure workflow steps.');
    }
}
