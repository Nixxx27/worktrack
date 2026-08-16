<?php

namespace App\Authorization;

use App\Enums\UserRole;
use App\Models\User;

/**
 * The §5.2 capability matrix, as data.
 *
 * This is layer TWO of authorization — the global role ceiling. It answers "may
 * this ROLE ever do this?" and nothing else. It deliberately knows nothing about
 * trackers: visibility is TrackerVisibilityScope's job, and both must pass.
 *
 * Keeping the two layers separate is what makes the model explainable. A Manager
 * who is not a member of a tracker fails at layer one and never reaches this table;
 * a Viewer who IS a member reaches it and is refused here.
 *
 * SETTLED (OQ9): Manager may move and edit ANY project within trackers they belong
 * to, including archiving. That is the whole point of the tier — delegating daily
 * board management without handing out Admin.
 */
final class CapabilityMatrix
{
    /**
     * Capability => roles permitted to attempt it.
     *
     * @var array<string, list<UserRole>>
     */
    private const MATRIX = [
        // ── Trackers: admin-only. Creating a tracker also grants its membership,
        //    so delegating it would let a Manager grant themselves visibility.
        Capability::CreateTracker->value => [UserRole::Admin],
        Capability::UpdateTracker->value => [UserRole::Admin],
        Capability::ArchiveTracker->value => [UserRole::Admin],
        Capability::ManageTrackerMembers->value => [UserRole::Admin],
        Capability::ConfigureSteps->value => [UserRole::Admin],

        // ── Projects
        Capability::ViewProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member, UserRole::Viewer],
        Capability::CreateProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        // ── SETTLED, and a deliberate reversal of the original §5.2 matrix.
        //
        // Members previously held only the *own* variants, so a Member could not move
        // or edit a card they neither owned nor were assigned to. In practice that
        // makes the board unusable as a shared board: the person who notices a ticket
        // is finished is rarely its nominal owner, and the workaround is to message
        // the owner and ask them to drag it — which is exactly the out-of-band
        // coordination this product exists to eliminate, and it means the board
        // lags reality, which makes every timing metric read late.
        //
        // The control that replaces the lock is the RECORD, not the restriction:
        // every move, edit, assignment and tag change writes an attributed row to
        // project_activities, reviewable on /activity. Trello's model — anyone on the
        // board can act, everyone can see who did.
        //
        // Note what did NOT loosen. Viewers still hold neither variant, membership is
        // still required by ProjectPolicy before either is consulted, and archiving
        // stays with Admin/Manager because it removes work from every report.
        Capability::UpdateAnyProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::UpdateOwnProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::MoveAnyProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::MoveOwnProject->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::ArchiveProject->value => [UserRole::Admin, UserRole::Manager],
        Capability::SetHealth->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],

        // ── Tasks, comments, files
        // Loosened with the project *any* variants above, and for the same reason: a
        // checklist that only its owner may tick is a checklist that goes stale. The
        // person who finishes a step is often not the person who wrote it down.
        Capability::ManageAnyTask->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::ManageOwnTask->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::Comment->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],
        Capability::UploadAttachment->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],

        // Viewers CAN download files in trackers they belong to — FR-4.5 (opening a
        // card shows its attachments) would be meaningless otherwise, and the
        // capability matrix denies Viewers only *upload*.
        Capability::DownloadAttachment->value => [UserRole::Admin, UserRole::Manager, UserRole::Member, UserRole::Viewer],
        Capability::DeleteAttachment->value => [UserRole::Admin, UserRole::Manager, UserRole::Member],

        // ── Administration
        Capability::ManageUsers->value => [UserRole::Admin],
        Capability::ViewAuditLog->value => [UserRole::Admin],
        Capability::ManageSettings->value => [UserRole::Admin],

        // Only Admins get true cross-organisation figures. Everyone else sees totals
        // scoped to their own trackers, labelled as such — see the note below.
        Capability::ViewAllTrackerMetrics->value => [UserRole::Admin],
    ];

    /**
     * May this user's ROLE ever attempt this capability?
     *
     * Status is checked first and unconditionally: a suspended admin is not an
     * admin. This duplicates the Gate::before check on purpose — this class is also
     * called outside the Gate (for UI affordances), and it must not be permissive
     * when used that way.
     */
    public static function allows(User $user, Capability $capability): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return in_array($user->role, self::MATRIX[$capability->value] ?? [], true);
    }

    /**
     * The capabilities available to a role, for rendering UI affordances.
     *
     * Never use this as the access control — hiding a button is presentation. Every
     * capability is re-checked server-side on the request that acts on it.
     *
     * @return list<Capability>
     */
    public static function for(User $user): array
    {
        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $c) => self::allows($user, $c),
        ));
    }
}
