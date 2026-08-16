<?php

namespace App\Enums;

/**
 * Health is INDEPENDENT of which column the card sits in (D5), so a project keeps
 * its real step while flagged — you see "stuck in In Progress for 21 days" rather
 * than losing its position on the board.
 */
enum ProjectHealth: string
{
    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case Stalled = 'stalled';   // auto-raised by inactivity, or set by hand
    case OnHold = 'on_hold';    // always manual; SUPPRESSES auto-stall (FR-4.6)
}
