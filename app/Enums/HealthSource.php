<?php

namespace App\Enums;

/**
 * Distinguishes an auto-raised flag from a deliberate human one.
 *
 * This is what lets activity auto-clear a Stalled project without overruling an
 * admin who set it by hand (FR-4.6), and it is why On Hold carries a CHECK
 * constraint requiring Manual.
 */
enum HealthSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
