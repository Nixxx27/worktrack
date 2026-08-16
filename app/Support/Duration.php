<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * NFR-U3 — timing is shown in plain language, never raw seconds or timestamps.
 *
 * "21 days" is actionable; "1814400" is not, and neither is a bare date the reader has
 * to subtract from today in their head.
 */
class Duration
{
    public static function humanDays(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $days = intdiv($seconds, 86400);

        if ($days >= 1) {
            return $days.'d';
        }

        $hours = intdiv($seconds, 3600);

        return $hours >= 1 ? $hours.'h' : max(1, intdiv($seconds, 60)).'m';
    }

    /**
     * Whole calendar days from today until $date — negative once the date has passed.
     *
     * Calendar days, not elapsed time. Something due tomorrow is "1 day" whether it is
     * now breakfast or midnight, which is how a person reads a deadline; a seconds-based
     * difference would call the same date "0 days" all evening and then "1 day" again
     * after midnight.
     *
     * Both sides are reduced to a Y-m-d in the org timezone before subtracting, because
     * a date column's Carbon is midnight UTC while "today" is local — subtracting them
     * directly is wrong by the offset, which for Asia/Manila is a whole day for eight
     * hours out of every twenty-four.
     */
    public static function daysUntil(CarbonInterface $date): int
    {
        $tz = config('worktrack.default_timezone');

        return (int) Carbon::parse(Carbon::now($tz)->toDateString())
            ->diffInDays(Carbon::parse($date->toDateString()), false);
    }

    /** "3 days late", "due today", "due in 2 days" — the deadline read out loud. */
    public static function deadlineWords(CarbonInterface $date): string
    {
        $days = self::daysUntil($date);

        return match (true) {
            $days < -1 => abs($days).' days late',
            $days === -1 => '1 day late',
            $days === 0 => 'due today',
            $days === 1 => 'due tomorrow',
            default => 'due in '.$days.' days',
        };
    }

    public static function words(?int $seconds): string
    {
        if ($seconds === null) {
            return 'no data';
        }

        $days = intdiv($seconds, 86400);

        return match (true) {
            $days >= 2 => $days.' days',
            $days === 1 => '1 day',
            default => max(1, intdiv($seconds, 3600)).' hours',
        };
    }
}
