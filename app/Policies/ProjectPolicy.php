<?php

namespace App\Policies;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        return $user->isMemberOf($project->tracker_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return CapabilityMatrix::allows($user, Capability::CreateProject);
    }

    public function update(User $user, Project $project): Response
    {
        return $this->anyOrOwn(
            $user, $project,
            Capability::UpdateAnyProject,
            Capability::UpdateOwnProject,
            'You can only edit projects you own or are assigned to.',
        );
    }

    /** FR-3.5 — the server-side check behind every drag-and-drop drop. */
    public function move(User $user, Project $project): Response
    {
        return $this->anyOrOwn(
            $user, $project,
            Capability::MoveAnyProject,
            Capability::MoveOwnProject,
            'You can only move projects you own or are assigned to.',
        );
    }

    public function archive(User $user, Project $project): Response
    {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::ArchiveProject)
            ? Response::allow()
            : Response::deny('Only an administrator or manager can archive a project.');
    }

    public function setHealth(User $user, Project $project): Response
    {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::SetHealth)
            ? Response::allow()
            : Response::deny('Viewers cannot change project health.');
    }

    /*
     * ── Abilities whose SUBJECT is a project but whose object is a child record ──
     *
     * These live here rather than on TaskPolicy / CommentPolicy / AttachmentPolicy
     * because Laravel resolves a policy from the class of the model handed to the
     * gate. An ability taking a Project has to be on the Project's policy or it is
     * never found — and an authorization check that is silently never found is worse
     * than one that is missing, because the call site looks correct.
     */

    /** FR-5.1 — add a checklist item. */
    public function createTask(User $user, Project $project): Response
    {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::ManageOwnTask)
            ? Response::allow()
            : Response::deny('Viewers cannot add tasks.');
    }

    /** FR-4.5 — post a comment. */
    public function createComment(User $user, Project $project): Response
    {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::Comment)
            ? Response::allow()
            : Response::deny('Viewers cannot comment.');
    }

    /**
     * FR-6.2 — attach a file.
     *
     * Open to any non-Viewer member, deliberately. VERIFICATION.md attachments-11
     * records the version restricted to the project owner: a network engineer
     * capturing a switch log on a colleague's incident card was refused, emailed the
     * file instead, and the artifact never became part of the record — the exact
     * out-of-band behaviour attachments exist to eliminate.
     */
    public function uploadAttachment(User $user, Project $project): Response
    {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::UploadAttachment)
            ? Response::allow()
            : Response::deny('Viewers cannot upload files.');
    }

    /**
     * Watch / unwatch.
     *
     * Any member, Viewers included: watching only changes your own inbox, and a
     * Viewer who is following a job is exactly the person who should be able to.
     */
    public function watch(User $user, Project $project): Response
    {
        return $user->isMemberOf($project->tracker_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * The "any, or only mine" shape shared by update and move.
     *
     * Ownership means owner OR assignee — FR-4.3 restricts both to tracker members,
     * so ownership can never grant rights across a tracker boundary.
     */
    private function anyOrOwn(
        User $user,
        Project $project,
        Capability $any,
        Capability $own,
        string $message,
    ): Response {
        if (! $user->isMemberOf($project->tracker_id)) {
            return Response::denyAsNotFound();
        }

        if (CapabilityMatrix::allows($user, $any)) {
            return Response::allow();
        }

        if (CapabilityMatrix::allows($user, $own) && $this->isOwnedBy($user, $project)) {
            return Response::allow();
        }

        return Response::deny($message);
    }

    private function isOwnedBy(User $user, Project $project): bool
    {
        return $project->owner_user_id === $user->id
            || $project->assignees()->whereKey($user->id)->exists();
    }
}
