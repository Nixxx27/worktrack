<?php

namespace App\Policies;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Checklist items (FR-5).
 *
 * Membership is layer one and is checked first in every method, so a denial for
 * someone outside the tracker is always a 404 — a 403 would confirm the task exists,
 * which is the disclosure FR-2.9 forbids.
 */
class TaskPolicy
{
    public function view(User $user, Task $task): Response
    {
        return $user->isMemberOf($task->tracker_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * NOTE: creating a task is authorized by ProjectPolicy::createTask, not here.
     * Laravel resolves a policy from the class of the model passed to the gate, so an
     * ability whose subject is a Project has to live on the Project's policy — putting
     * it here would silently never be found, which is the worst possible outcome for
     * an authorization check.
     */
    public function update(User $user, Task $task): Response
    {
        return $this->decide($user, $task->tracker_id, $task);
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->decide($user, $task->tracker_id, $task);
    }

    /**
     * The "any, or only mine" shape.
     *
     * Both variants are now held by Member (see CapabilityMatrix), so in practice any
     * member of the tracker may manage any task on it. The own-variant branch is kept
     * rather than deleted because it is what the Viewer role falls through to, and
     * because it is the branch that would matter again if the matrix ever tightens.
     */
    private function decide(User $user, int $trackerId, ?Task $task): Response
    {
        if (! $user->isMemberOf($trackerId)) {
            return Response::denyAsNotFound();
        }

        if (CapabilityMatrix::allows($user, Capability::ManageAnyTask)) {
            return Response::allow();
        }

        $isMine = $task !== null && (
            $task->assignee_user_id === $user->id
            || $task->created_by_user_id === $user->id
            || $task->project?->owner_user_id === $user->id
        );

        if ($isMine && CapabilityMatrix::allows($user, Capability::ManageOwnTask)) {
            return Response::allow();
        }

        return Response::deny('Viewers cannot change tasks.');
    }
}
