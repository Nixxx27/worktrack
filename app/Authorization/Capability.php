<?php

namespace App\Authorization;

/**
 * Every distinct thing a user can attempt, as data rather than scattered ifs.
 *
 * Encoding the §5.2 capability matrix as a table means the permission model can be
 * read in one place and diffed in one place, instead of being reconstructed by
 * grepping for `if ($user->role === ...)` across a dozen policies.
 */
enum Capability: string
{
    // Trackers — admin-only by design (D8/D9)
    case CreateTracker = 'tracker.create';
    case UpdateTracker = 'tracker.update';
    case ArchiveTracker = 'tracker.archive';
    case ManageTrackerMembers = 'tracker.members.manage';
    case ConfigureSteps = 'tracker.steps.configure';

    // Projects
    case ViewProject = 'project.view';
    case CreateProject = 'project.create';
    case UpdateAnyProject = 'project.update.any';
    case UpdateOwnProject = 'project.update.own';
    case MoveAnyProject = 'project.move.any';
    case MoveOwnProject = 'project.move.own';
    case ArchiveProject = 'project.archive';
    case SetHealth = 'project.health.set';

    // Tasks, comments, files
    case ManageAnyTask = 'task.manage.any';
    case ManageOwnTask = 'task.manage.own';
    case Comment = 'comment.create';
    case UploadAttachment = 'attachment.upload';
    case DownloadAttachment = 'attachment.download';
    case DeleteAttachment = 'attachment.delete';

    // Administration
    case ManageUsers = 'users.manage';
    case ViewAuditLog = 'audit.view';
    case ManageSettings = 'settings.manage';
    case ViewAllTrackerMetrics = 'metrics.view.all';
}
