<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work (NFR-R2).
 *
 * Every schedule is pinned to the organisation's timezone. Without that, "daily at
 * 06:00" means 06:00 UTC — 14:00 in Manila — and the stall sweep would run in the
 * middle of the working day, flagging projects people are actively about to touch.
 *
 * PRODUCTION REQUIREMENT: none of this runs unless something invokes
 * `php artisan schedule:run` every minute, and notifications additionally need a queue
 * worker. Both are still open items (OQ10) — until they are supervised, stall detection
 * silently never happens, which is the failure mode NFR-R1 warns about.
 */

$tz = config('worktrack.default_timezone', 'Asia/Manila');

Schedule::command('worktrack:detect-stalled')
    ->dailyAt('06:00')
    ->timezone($tz)
    // Before the working day, so the watchlist is accurate when the owner first looks,
    // and so a flag never lands mid-conversation about the same project.
    ->withoutOverlapping()
    ->onOneServer();

/*
 * FR-7 — drains the notification outbox.
 *
 * Every minute, not on a longer cycle: the coalesce window (default 5 minutes) is what
 * decides how long a step change waits, and a slower poll would add its own delay on top
 * of a window that was already chosen deliberately.
 *
 * withoutOverlapping() bounds duplicate delivery rather than preventing it. The
 * dispatcher claims rows with SKIP LOCKED and reclaims anything abandoned in 'sending',
 * so two concurrent runs are safe — this only stops a slow run from being lapped by the
 * next tick and doing the same work twice.
 */
Schedule::command('worktrack:dispatch-notifications')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
