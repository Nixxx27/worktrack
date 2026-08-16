<?php

namespace App\Enums;

/**
 * Global role — the CEILING on what a user can ever do.
 *
 * This is only half of the authorization model. Tracker membership decides what
 * you can SEE; this decides what you can DO with it. Both must pass
 * (docs/ARCHITECTURE.md §4).
 */
enum UserRole: string
{
    case Admin = 'admin';       // full control, sees every tracker, approves users
    case Manager = 'manager';   // full control within trackers they belong to
    case Member = 'member';     // create projects/tasks; move what they own or are assigned
    case Viewer = 'viewer';     // read-only — the default on approval

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Admins are the only role that bypasses tracker membership. */
    public function seesAllTrackers(): bool
    {
        return $this === self::Admin;
    }
}
