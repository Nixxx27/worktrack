<?php

namespace App\Enums;

/**
 * Where a live card stands against its due date. Derived, never stored.
 *
 * Deliberately separate from ProjectHealth. Health answers "is anyone touching
 * this" (auto-stall on inactivity, or a manual flag); this answers "will it land
 * when promised". A card can be busy and late, so the two disagreeing is
 * information, not a bug, and folding one into the other would hide half of it.
 */
enum ScheduleStatus: string
{
    case Overdue = 'overdue';
    case DueSoon = 'due_soon';
    case OnSchedule = 'on_schedule';
    case Undated = 'undated';

    /** Worst first, so a sort on this puts what needs chasing at the top. */
    public function rank(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::DueSoon => 1,
            self::OnSchedule => 2,
            self::Undated => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Overdue',
            self::DueSoon => 'Due soon',
            self::OnSchedule => 'On schedule',
            self::Undated => 'No due date',
        };
    }
}
