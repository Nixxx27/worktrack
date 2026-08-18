<?php

use App\Authorization\AccessContext;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use App\Livewire\ProjectDrawer;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TaskService;
use App\Services\Trackers\TrackerService;
use App\Support\Duration;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * NFR-U3 — every date the product shows leads with its weekday.
 *
 * "due 20 Aug" is a date the reader has to take to a calendar before it means
 * anything: whether that is a Thursday there is still time to use or a Saturday
 * nobody will be in is the whole question, and it is the part the number does not
 * answer. Duration::dayDate() and shortDayDate() are the single place that decides
 * how a date is written, so the board, the drawer, the dashboard and the outbox
 * cannot drift into four spellings of the same day.
 *
 * The dates here are fixed literals, not offsets from now(): a weekday assertion
 * written as now()->addDays(3) names a different day every day the suite runs, and
 * is green by luck. August 2026 runs Mon 17, Thu 20, Mon 24, Fri 28.
 */
function weekdayAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    config(['worktrack.default_timezone' => 'Asia/Manila']);

    // Monday 17 Aug 2026, office hours. Pinned because half of these assertions turn
    // on whether a date has passed, and "overdue · " replaces "due " when it has.
    $this->travelTo(Carbon::parse('2026-08-17 09:00:00', 'Asia/Manila'));

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
});

describe('how a date is written', function () {

    it('puts the weekday in front of a full date', function () {
        expect(Duration::dayDate(Carbon::parse('2026-08-17')))->toBe('Mon 17 Aug 2026');
    });

    it('drops the year on a short date but never the day', function () {
        expect(Duration::shortDayDate(Carbon::parse('2026-08-20')))->toBe('Thu 20 Aug');
    });

    /*
     * A missing date is a fact about the work — no start, no due date — and blanking
     * it reads as a rendering fault rather than as an answer.
     */
    it('names a missing date rather than blanking it', function () {
        expect(Duration::dayDate(null))->toBe('—')
            ->and(Duration::dayDate(null, 'No start'))->toBe('No start')
            ->and(Duration::shortDayDate(null))->toBe('—');
    });
});

describe('the screens that show dates', function () {

    it('leads both ends of the drawer date span with a weekday', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Partners Offsell',
            'start_date' => '2026-08-17',
            'target_date' => '2026-08-24',
        ], $this->admin);

        weekdayAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('Mon 17 Aug 2026')
            ->assertSee('Mon 24 Aug 2026');
    });

    it('still says what is missing when there is no start date', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Firewall upgrade',
            'target_date' => '2026-08-24',
        ], $this->admin);

        weekdayAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('No start')
            ->assertSee('Mon 24 Aug 2026');
    });

    it('dates a checklist row by weekday', function () {
        $project = app(ProjectService::class)->create($this->tracker, [
            'name' => 'Cutover rehearsal',
        ], $this->admin);

        app(TaskService::class)->create($project, [
            'title' => 'Development',
            'due_date' => '2026-08-20',
        ], $this->admin);

        weekdayAs($this->admin)->test(ProjectDrawer::class)
            ->call('openFor', $project->public_id)
            ->assertSee('due Thu 20 Aug');
    });

    it('dates a card front by weekday', function () {
        app(ProjectService::class)->create($this->tracker, [
            'name' => 'JGC System',
            'target_date' => '2026-08-28',
        ], $this->admin);

        weekdayAs($this->admin)->test(Board::class)
            ->assertSee('due Fri 28 Aug');
    });

    it('dates the overdue list by weekday', function () {
        app(ProjectService::class)->create($this->tracker, [
            'name' => 'Late migration',
            'target_date' => '2026-08-14',
        ], $this->admin);

        weekdayAs($this->admin)->test(Dashboard::class)
            ->assertSee('Fri 14 Aug 2026');
    });
});
