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

    /**
     * "Mon 17 Aug 2026" — the weekday in front of the date.
     *
     * Work is agreed in weekdays. "Due 20 Aug" makes the reader go and find a calendar
     * to learn whether that is a Thursday there is still time to use or a Sunday nobody
     * will be in, and a date that has to be looked up somewhere else is not the plain
     * language NFR-U3 asks for. The day goes in front because that is the part being
     * asked about; the number answers "which one" once the day has answered "when".
     *
     * Null returns $fallback rather than an empty string: a missing date is a fact about
     * the work — no start, no due date — and blanking it reads as a rendering fault.
     */
    public static function dayDate(?CarbonInterface $date, string $fallback = '—'): string
    {
        return $date?->format('D j M Y') ?? $fallback;
    }

    /**
     * The same date without the year, for the places already dense with numbers — a card
     * front, a checklist row. The year is dropped, never the day: a due date this month
     * needs its weekday far more than it needs to say 2026.
     */
    public static function shortDayDate(?CarbonInterface $date, string $fallback = '—'): string
    {
        return $date?->format('D j M') ?? $fallback;
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
