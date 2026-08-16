<?php

namespace App\Enums;

/**
 * The semantic type of a board column. This drives EVERY metric.
 *
 * Cross-tracker reporting groups by this and never by step name, because each
 * tracker names its own columns and "In Progress" in one is not comparable to
 * "Development" in another (FR-3.7 / FR-8.8).
 */
enum StepType: string
{
    case Intake = 'intake';       // waiting, not working — does not start the cycle clock
    case Active = 'active';       // working — starts and accrues cycle time
    case Terminal = 'terminal';   // done — stops the clock

    /** Auto-stall applies to intake and active only; finished work is not "stalled". */
    public function isStallEligible(): bool
    {
        return $this !== self::Terminal;
    }
}
