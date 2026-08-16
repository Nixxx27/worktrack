<?php

namespace App\Enums;

/**
 * Account lifecycle. Signup is open to any Google account (D6) because the dev
 * team uses personal Gmail, so `pending` is the gate that makes that safe: a
 * pending user can see nothing at all until an admin approves them (FR-1.3).
 */
enum UserStatus: string
{
    case Pending = 'pending';       // signed up, awaiting approval — sees ONLY the waiting screen
    case Active = 'active';         // approved
    case Suspended = 'suspended';   // denied on their very next request (FR-1.7)
    case Rejected = 'rejected';     // application declined
    case Blocked = 'blocked';       // may not re-apply

    /** The ONLY status permitted to do anything. Everything else fails closed. */
    public function canAccessApplication(): bool
    {
        return $this === self::Active;
    }
}
