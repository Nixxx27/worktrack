<?php

namespace App\Policies;

use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Files (FR-6.4).
 *
 * The download check is the one that matters most: it is the gate in front of a
 * signed URL, and a signed URL, once minted, is a bearer token that works for anyone
 * holding it. So it is checked on the request that MINTS the URL, every time, against
 * the denormalized tracker_id on the attachment row rather than by traversing
 * attachment → task → project → tracker. That traversal is the shape of code where an
 * isolation bug hides, which is why the column is denormalized in the first place.
 *
 * VERIFICATION.md attachments-11 records the failure this policy is written against:
 * an earlier design restricted upload to the project owner, so a network engineer
 * capturing a switch log on a colleague's incident card was refused, emailed the file
 * instead, and the artifact never became part of the record. Upload is therefore open
 * to any non-Viewer member of the tracker.
 */
class AttachmentPolicy
{
    /**
     * FR-4.5 — Viewers CAN download, because "opening a card shows its attachments"
     * is meaningless otherwise. The capability matrix denies Viewers only upload.
     */
    public function download(User $user, Attachment $attachment): Response
    {
        if (! $user->isMemberOf($attachment->tracker_id)) {
            return Response::denyAsNotFound();
        }

        // A pending, failed or deleting row has no business being signed, and saying
        // "not found" rather than "not available" keeps the two indistinguishable.
        if ($attachment->status !== 'available') {
            return Response::denyAsNotFound();
        }

        return CapabilityMatrix::allows($user, Capability::DownloadAttachment)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    // NOTE: uploading is authorized by ProjectPolicy::uploadAttachment, for the same
    // policy-resolution reason noted in TaskPolicy.

    public function delete(User $user, Attachment $attachment): Response
    {
        if (! $user->isMemberOf($attachment->tracker_id)) {
            return Response::denyAsNotFound();
        }

        if (! CapabilityMatrix::allows($user, Capability::DeleteAttachment)) {
            return Response::deny('Viewers cannot delete files.');
        }

        // Deleting a file destroys an artifact that cannot be recovered from the
        // activity log the way an edited field can. So it stays with the person who
        // uploaded it, plus the roles that already carry destructive authority.
        if ($attachment->uploaded_by_user_id === $user->id) {
            return Response::allow();
        }

        return CapabilityMatrix::allows($user, Capability::ArchiveProject)
            ? Response::allow()
            : Response::deny('You can only delete files you uploaded.');
    }
}
