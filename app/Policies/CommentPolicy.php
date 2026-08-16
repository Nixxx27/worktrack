<?php

namespace App\Policies;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Comments (FR-4.5).
 *
 * The one place in this codebase where the *any* variant was deliberately NOT
 * loosened along with projects and tasks. Editing someone else's words is different
 * in kind from moving their card: a card is a shared record of work, and a comment is
 * a person's own statement. Being able to silently rewrite what a colleague said —
 * in a thread others have already replied to — is not a permission a flat team model
 * needs, and no amount of activity logging makes it comfortable.
 *
 * So: anyone on the tracker may comment; only the author may edit their own comment;
 * and deletion is the author or an Admin/Manager, who need it for genuinely
 * inappropriate content.
 */
class CommentPolicy
{
    public function view(User $user, Comment $comment): Response
    {
        return $user->isMemberOf($comment->tracker_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    // NOTE: posting a comment is authorized by ProjectPolicy::createComment. An
    // ability whose subject is a Project must live on the Project's policy, or
    // Laravel never finds it — a silently absent authorization check.

    public function update(User $user, Comment $comment): Response
    {
        if (! $user->isMemberOf($comment->tracker_id)) {
            return Response::denyAsNotFound();
        }

        // Not even an Admin. An admin who needs a comment gone can delete it, which
        // is visible; editing it would not be.
        return $comment->user_id === $user->id
            ? Response::allow()
            : Response::deny('You can only edit your own comments.');
    }

    public function delete(User $user, Comment $comment): Response
    {
        if (! $user->isMemberOf($comment->tracker_id)) {
            return Response::denyAsNotFound();
        }

        if ($comment->user_id === $user->id) {
            return Response::allow();
        }

        // Moderation, and it leaves a row in the activity feed naming who did it.
        return CapabilityMatrix::allows($user, Capability::ArchiveProject)
            ? Response::allow()
            : Response::deny('Only the author, a manager or an administrator can delete a comment.');
    }
}
