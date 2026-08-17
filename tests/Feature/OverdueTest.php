<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\ProjectDrawer;
use App\Models\Step;
use App\Models\User;
use App\Services\Metrics\MetricsRepository;
use App\Services\Projects\MovementRecorder;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TaskService;
use App\Services\Trackers\TrackerService;
use Livewire\Livewire;

/**
 * NFR-U5 — a promised date is kept in the ORG's calendar, not the server's.
 *
 * The bug these pin: target_date is a bare date, so its Carbon is midnight in
 * app.timezone (UTC). The board and drawer used ->isPast(), which turns true at
 * 08:00 Manila on the due day itself — every card spent the entire working day it
 * was promised for painted red, a day early. MetricsRepository already compared
 * date-to-date and so disagreed with the two views it sits next to.
 *
 * Every test here travels to 09:00 Manila, INSIDE that broken window, because a
 * test written at any hour before 08:00 local passes against the old code too.
 */
function overdueAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    config(['worktrack.default_timezone' => 'Asia/Manila']);

    // 09:00 Manila = 01:00 UTC, the same clock the screenshot was taken at: the due
    // day has begun locally, midnight UTC on that date has already passed.
    $this->travelTo(now()->setTimezone('Asia/Manila')->setTime(9, 0)->setTimezone('UTC'));

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);

    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));

    $this->today = now(config('worktrack.default_timezone'))->toDateString();

    $this->makeProject = fn (string $name, ?string $target) => app(ProjectService::class)
        ->create($this->tracker, ['name' => $name, 'target_date' => $target], $this->admin);
});

afterEach(fn () => $this->travelBack());

it('does not treat a project due today as overdue', function () {
    $project = ($this->makeProject)('Live Deployment', $this->today);

    expect($project->isOverdue())->toBeFalse();
});

it('treats a project due yesterday as overdue', function () {
    $yesterday = now(config('worktrack.default_timezone'))->subDay()->toDateString();

    expect(($this->makeProject)('Late one', $yesterday)->isOverdue())->toBeTrue();
});

it('holds a due-today project on time until the local day actually ends', function () {
    $project = ($this->makeProject)('Live Deployment', $this->today);

    // 23:30 Manila is 15:30 UTC — deep past midnight UTC on the due date, and still
    // the promised day here. This is the assertion ->isPast() cannot satisfy.
    $this->travelTo(now(config('worktrack.default_timezone'))->setTime(23, 30)->setTimezone('UTC'));

    expect($project->isOverdue())->toBeFalse();

    // One minute into tomorrow, locally, it is late.
    $this->travelTo(now(config('worktrack.default_timezone'))->addDay()->startOfDay()->addMinute()->setTimezone('UTC'));

    expect($project->fresh()->isOverdue())->toBeTrue();
});

it('never calls a finished project overdue', function () {
    $project = ($this->makeProject)('Shipped late', now(config('worktrack.default_timezone'))->subMonth()->toDateString());

    $movements = app(MovementRecorder::class);
    $movements->recordMove($project, $this->steps['In Progress'], $this->admin);
    $movements->recordMove($project->fresh(), $this->steps['Done'], $this->admin);

    // A month late and finished: a fact for the report, not a red flag on the board.
    expect($project->fresh()->isOverdue())->toBeFalse();
});

it('leaves a project with no target date out of the question entirely', function () {
    expect(($this->makeProject)('Unpromised', null)->isOverdue())->toBeFalse();
});

it('does not badge a due-today card as overdue on the board', function () {
    ($this->makeProject)('Live Deployment', $this->today);

    overdueAs($this->admin)
        ->test(Board::class, ['tracker' => $this->tracker->public_id])
        ->assertDontSee('overdue')
        ->assertSee('due');
});

it('does not tell the drawer a due-today project is overdue', function () {
    $project = ($this->makeProject)('Live Deployment', $this->today);

    overdueAs($this->admin)
        ->test(ProjectDrawer::class)
        ->call('openFor', $project->public_id)
        ->assertDontSee('overdue since')
        ->assertDontSee('Overdue');
});

it('agrees with the dashboard about what is late', function () {
    ($this->makeProject)('Due today', $this->today);
    ($this->makeProject)('Due yesterday', now(config('worktrack.default_timezone'))->subDay()->toDateString());

    $deadlines = app(MetricsRepository::class)->deadlines($this->tracker->id);

    // The whole point of routing both through one rule: the count the head reads and
    // the colour the member sees are derived the same way.
    expect($deadlines['overdue'])->toHaveCount(1)
        ->and($deadlines['overdue']->first()->name)->toBe('Due yesterday')
        ->and($deadlines['due_soon']->pluck('name'))->toContain('Due today');
});

it('does not redden a task due today', function () {
    $project = ($this->makeProject)('Live Deployment', $this->today);

    $tasks = app(TaskService::class);
    $dueToday = $tasks->create($project, ['title' => 'Cutover', 'due_date' => $this->today], $this->admin);
    $dueYesterday = $tasks->create($project, [
        'title' => 'Backups',
        'due_date' => now(config('worktrack.default_timezone'))->subDay()->toDateString(),
    ], $this->admin);

    expect($dueToday->isOverdue())->toBeFalse()
        ->and($dueYesterday->isOverdue())->toBeTrue();
});

it('never calls a completed task overdue', function () {
    $project = ($this->makeProject)('Live Deployment', $this->today);

    $tasks = app(TaskService::class);
    $task = $tasks->create($project, [
        'title' => 'Backups',
        'due_date' => now(config('worktrack.default_timezone'))->subWeek()->toDateString(),
    ], $this->admin);

    expect($task->isOverdue())->toBeTrue();

    $tasks->setDone($task, true, $this->admin);

    expect($task->fresh()->isOverdue())->toBeFalse();
});
