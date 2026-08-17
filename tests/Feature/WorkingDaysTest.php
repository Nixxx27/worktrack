<?php

use App\Authorization\AccessContext;
use App\Enums\UserRole;
use App\Livewire\ProjectDrawer;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Livewire\Livewire;

/**
 * The commitment length shown in the drawer's DATES block.
 *
 * "17 Aug 2026 → 24 Aug 2026" is a pair of dates, and reading how much work was
 * promised out of it means counting weekdays off a calendar by hand. Project::
 * workingDays() does that counting: inclusive of both ends, Saturdays and Sundays
 * excluded, null when there is nothing to measure.
 *
 * The dates are fixed literals rather than offsets from now() on purpose — a span
 * expressed as "now()->addDays(7)" lands on a different weekday every day the suite
 * runs, and a weekend-sensitive assertion written that way is green by luck.
 */
function workingDaysAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    config(['worktrack.default_timezone' => 'Asia/Manila']);

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);

    $this->span = fn (?string $start, ?string $target) => new Project([
        'start_date' => $start,
        'target_date' => $target,
    ]);
});

describe('counting the span', function () {

    it('counts both ends of the span, so a one-day commitment is one day', function () {
        // Tuesday to Tuesday. The gap between the dates is zero; the work is not.
        expect(($this->span)('2026-08-18', '2026-08-18')->workingDays())->toBe(1);
    });

    it('drops the weekend out of a Monday-to-Monday span', function () {
        // 17 Aug 2026 is a Monday: Mon–Fri, then the following Mon. Six, not eight.
        expect(($this->span)('2026-08-17', '2026-08-24')->workingDays())->toBe(6);
    });

    it('counts a full working week as five days', function () {
        expect(($this->span)('2026-08-17', '2026-08-21')->workingDays())->toBe(5);
    });

    it('returns zero for a span that is only a weekend', function () {
        // Sat + Sun. Zero is the honest answer here — no working time was promised —
        // and it is distinct from the null a missing date gives.
        expect(($this->span)('2026-08-22', '2026-08-23')->workingDays())->toBe(0);
    });

    it('ignores weekend edges rather than rounding them into the count', function () {
        // Saturday to the following Sunday: nine calendar days, five of them work.
        expect(($this->span)('2026-08-22', '2026-08-30')->workingDays())->toBe(5);
    });

    it('stays exact across whole weeks and their leftover tail', function () {
        // Four weeks and a day, starting Monday: 4 × 5 + the trailing Monday.
        expect(($this->span)('2026-08-17', '2026-09-14')->workingDays())->toBe(21);
    });

    it('holds up across a year boundary', function () {
        // 2026-12-28 is a Monday, 2027-01-08 a Friday: two full working weeks.
        expect(($this->span)('2026-12-28', '2027-01-08')->workingDays())->toBe(10);
    });
});

describe('when there is nothing to measure', function () {

    it('is null without a start date', function () {
        expect(($this->span)(null, '2026-08-24')->workingDays())->toBeNull();
    });

    it('is null without a target date', function () {
        expect(($this->span)('2026-08-17', null)->workingDays())->toBeNull();
    });

    it('is null with neither date', function () {
        expect(($this->span)(null, null)->workingDays())->toBeNull();
    });

    /*
     * ProjectService::assertDateOrder rejects this on the way in, so it can only
     * arrive from a direct write. Null rather than a negative or a zero: it is bad
     * data, and printing "0 working days" for it would hide that behind a number
     * that looks deliberate.
     */
    it('is null when the target lands before the start', function () {
        expect(($this->span)('2026-08-24', '2026-08-17')->workingDays())->toBeNull();
    });
});

describe('the drawer', function () {

    it('shows the working-day span beside the dates', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Partners Offsell',
            'start_date' => '2026-08-17',
            'target_date' => '2026-08-24',
        ], $this->admin);

        workingDaysAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('6 working days');
    });

    it('says day, not days, for a one-day commitment', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Cutover rehearsal',
            'start_date' => '2026-08-18',
            'target_date' => '2026-08-18',
        ], $this->admin);

        workingDaysAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('1 working day')
            ->assertDontSee('1 working days');
    });

    it('says nothing about the span when a date is missing', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Firewall upgrade',
            'target_date' => '2026-08-24',
        ], $this->admin);

        workingDaysAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('No start')
            ->assertDontSee('working day');
    });

    /*
     * Overdue already owns this line. Two competing summaries under one date range
     * read as noise, and the alarm is the one that has to win.
     */
    it('gives the line to the overdue alarm rather than the span', function () {
        $this->travelTo(now()->setTimezone('Asia/Manila')->setTime(9, 0)->setTimezone('UTC'));

        $manila = now(config('worktrack.default_timezone'));

        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Late migration',
            'start_date' => $manila->copy()->subDays(10)->toDateString(),
            'target_date' => $manila->copy()->subDays(3)->toDateString(),
        ], $this->admin);

        workingDaysAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('Overdue')
            ->assertDontSee('working day');

        $this->travelBack();
    });
});
