<?php

namespace App\Enums;

/**
 * Who a card is for — the second visibility axis, under tracker membership.
 *
 * Tracker membership answers "which board is this on"; this answers "and who on that
 * board may see it". Both must pass, and this one is deliberately the narrower: a
 * private card is still owned by its tracker (its step, its tags, its metrics all
 * belong to that tracker) — it is simply not shown to the other members.
 *
 * PRIVATE MEANS THE OWNER, and only the owner. Not "the owner and the admins": the
 * whole reason the setting exists is to give one person a place to track work that
 * has no audience, and a private card an admin can read is not that. That makes this
 * the first and only exception to D9 (admins see every tracker) — recorded in
 * docs/ARCHITECTURE.md rather than left to be rediscovered from this enum.
 */
enum ProjectVisibility: string
{
    case Tracker = 'tracker';   // every member of the tracker — the default
    case Private = 'private';   // the owner alone

    public function label(): string
    {
        return match ($this) {
            self::Tracker => 'Everyone on this tracker',
            self::Private => 'Only me',
        };
    }

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
