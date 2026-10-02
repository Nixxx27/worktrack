<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\ProjectVisibility;
use App\Enums\ScheduleStatus;
use App\Enums\StepType;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Step;
use App\Models\Tracker;
use App\Services\Metrics\MetricsRepository;
use App\Services\Trackers\StepService;
use App\Services\Trackers\TrackerService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The dashboard schedule summary: every card in a step flagged show_in_summary,
 * with its start, its due date and a verdict against it.
 *
 * The verdict is pinned against a frozen FRIDAY on purpose. "Due soon" counts
 * working days, and the weekend is where a calendar-day count and a working-day
 * count disagree — a Monday due date read on a Friday is one working day away,
 * not three.
 */
function summaryCard(Tracker $tracker, Step $step, string $name, ?string $start, ?string $due, array $extra = []): Project
{
    return SystemContext::run(fn () => Project::create([
        'tracker_id' => $tracker->id,
        'step_id' => $step->id,
        'current_step_type' => $step->type,
        'name' => $name,
        'start_date' => $start,
        'target_date' => $due,
        'last_activity_at' => now(),
        'current_step_entered_at' => now(),
    ] + $extra));
}

function summaryFor(?int $trackerId = null): array
{
    return app(MetricsRepository::class)->scheduleSummary($trackerId);
}

beforeEach(function () {
    // Friday 2 Oct 2026, 09:00 in Manila.
    $this->travelTo(Carbon::parse('2026-10-02 01:00:00', 'UTC'));

    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->steps = SystemContext::run(fn () => Step::where('tracker_id', $this->tracker->id)
        ->orderBy('position')->get()->keyBy('name'));
});

describe('the schedule verdict', function () {

    it('reads each card against its due date in working days', function (?string $due, ?ScheduleStatus $expected) {
        $card = summaryCard($this->tracker, $this->steps['In Progress'], 'Card', '2026-09-01', $due);

        expect($card->scheduleStatus())->toBe($expected);
    })->with([
        'due yesterday is overdue' => ['2026-10-01', ScheduleStatus::Overdue],
        'due today is due soon, not late' => ['2026-10-02', ScheduleStatus::DueSoon],
        'due Monday is one working day away' => ['2026-10-05', ScheduleStatus::DueSoon],
        'due Wednesday is three working days away' => ['2026-10-07', ScheduleStatus::DueSoon],
        'due Thursday is four working days away' => ['2026-10-08', ScheduleStatus::OnSchedule],
        'due in three weeks' => ['2026-10-23', ScheduleStatus::OnSchedule],
        'no due date' => [null, ScheduleStatus::Undated],
    ]);

    it('gives no verdict on finished work', function () {
        $card = summaryCard($this->tracker, $this->steps['Done'], 'Shipped', '2026-09-01', '2026-09-10');

        expect($card->scheduleStatus())->toBeNull();
    });
});

describe('which steps the summary watches', function () {

    it('watches the working column of a new tracker and nothing else', function () {
        expect($this->steps->map->show_in_summary->all())->toBe([
            'Backlog' => false,
            'New' => false,
            'In Progress' => true,
            'Done' => false,
        ]);
    });

    it('watches a newly added active step without being told to', function () {
        $qa = app(StepService::class)->create($this->tracker, ['name' => 'QA', 'type' => StepType::Active], $this->admin);
        $todo = app(StepService::class)->create($this->tracker, ['name' => 'To Do', 'type' => StepType::Intake], $this->admin);

        expect($qa->show_in_summary)->toBeTrue()
            ->and($todo->show_in_summary)->toBeFalse();
    });

    it('lists only cards in watched steps, and follows the flag when it changes', function () {
        summaryCard($this->tracker, $this->steps['In Progress'], 'Core switch', '2026-09-01', '2026-10-20');
        summaryCard($this->tracker, $this->steps['Backlog'], 'Someday', null, null);

        expect(summaryFor()['total'])->toBe(1);

        app(StepService::class)->setShowInSummary($this->steps['Backlog'], true, $this->admin);

        expect(summaryFor()['total'])->toBe(2);
    });

    it('reports when no step is watched at all, so the panel can say why it is empty', function () {
        app(StepService::class)->setShowInSummary($this->steps['In Progress'], false, $this->admin);

        expect(summaryFor()['steps_watched'])->toBe(0);
    });

    it('does not audit a toggle that changes nothing', function () {
        expect(app(StepService::class)->setShowInSummary($this->steps['In Progress'], true, $this->admin))
            ->toBeFalse();
    });
});

describe('the summary itself', function () {

    it('puts the worst first and counts each verdict', function () {
        $step = $this->steps['In Progress'];
        summaryCard($this->tracker, $step, 'On schedule', '2026-09-20', '2026-10-30');
        summaryCard($this->tracker, $step, 'Undated', '2026-09-20', null);
        summaryCard($this->tracker, $step, 'Late', '2026-08-01', '2026-08-28');
        summaryCard($this->tracker, $step, 'Soon', '2026-09-20', '2026-10-05');

        $summary = summaryFor();

        expect($summary['groups'])->toHaveCount(1)
            ->and($summary['groups'][0]['rows']->map(fn ($r) => $r['project']->name)->all())
            ->toBe(['Late', 'Soon', 'On schedule', 'Undated'])
            ->and($summary['counts'])->toBe([
                'overdue' => 1,
                'due_soon' => 1,
                'on_schedule' => 1,
                'undated' => 1,
            ]);
    });

    it('leaves private cards out, as every dashboard figure does', function () {
        summaryCard($this->tracker, $this->steps['In Progress'], 'Mine only', null, '2026-10-30', [
            'visibility' => ProjectVisibility::Private,
            'owner_user_id' => $this->admin->id,
        ]);

        expect(summaryFor()['total'])->toBe(0);
    });

    it('keeps another tracker\'s cards out of a member\'s summary', function () {
        $other = app(TrackerService::class)->create(['name' => 'Systems Development'], $this->admin);
        $otherStep = SystemContext::run(fn () => Step::where('tracker_id', $other->id)->where('name', 'In Progress')->first());
        summaryCard($other, $otherStep, 'Not yours', null, '2026-10-30');
        summaryCard($this->tracker, $this->steps['In Progress'], 'Yours', null, '2026-10-30');

        $member = makeUser('joy@gmail.com', UserRole::Member);
        app(TrackerService::class)->addMember($this->tracker, $member, $this->admin);
        bindContextFor($member);

        $names = summaryFor()['groups']->flatMap(fn ($g) => $g['rows'])->map(fn ($r) => $r['project']->name);

        expect($names->all())->toBe(['Yours']);
    });

    it('renders on the dashboard with start, due and verdict', function () {
        summaryCard($this->tracker, $this->steps['In Progress'], 'Starlink quotation', '2026-08-03', '2026-08-28');

        app(AccessContext::class)->forUser($this->admin);

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSee('Schedule')
            ->assertSee('Starlink quotation')
            ->assertSee('Mon 3 Aug')
            ->assertSee('1 Overdue');
    });
});

describe('the settings toggle', function () {

    it('lets an admin turn a step on', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.summary', [$this->tracker, $this->steps['Backlog']]), ['show_in_summary' => 1])
            ->assertRedirect();

        bindContextFor($this->admin);

        expect($this->steps['Backlog']->fresh()->show_in_summary)->toBeTrue();
    });

    it('refuses a member', function () {
        $member = makeUser('joy@gmail.com', UserRole::Member);
        app(TrackerService::class)->addMember($this->tracker, $member, $this->admin);

        $this->actingAs($member)
            ->post(route('admin.trackers.steps.summary', [$this->tracker, $this->steps['Backlog']]), ['show_in_summary' => 1])
            ->assertForbidden();
    });
});

describe('on the board', function () {

    it('shows the schedule strip and a watched column\'s late count', function () {
        summaryCard($this->tracker, $this->steps['In Progress'], 'Wi-Fi survey', '2026-08-03', '2026-09-04');
        summaryCard($this->tracker, $this->steps['In Progress'], 'Firewall audit', '2026-09-20', '2026-10-30');
        summaryCard($this->tracker, $this->steps['Backlog'], 'Not watched', null, '2026-08-01');

        app(AccessContext::class)->forUser($this->admin);

        $board = Livewire::actingAs($this->admin)->test(Board::class, ['tracker' => $this->tracker->public_id]);

        expect($board->get('schedule')['counts'])->toBe([
            'overdue' => 1,
            'due_soon' => 0,
            'on_schedule' => 1,
            'undated' => 0,
        ]);

        $board->assertSee('Schedule')
            ->assertSee('1 late')
            ->assertSee('1 on schedule')
            ->assertSee('Fri 4 Sep');
    });

    it('follows the board filters, so the strip never disagrees with the columns', function () {
        summaryCard($this->tracker, $this->steps['In Progress'], 'Wi-Fi survey', '2026-08-03', '2026-09-04');
        summaryCard($this->tracker, $this->steps['In Progress'], 'Firewall audit', '2026-09-20', '2026-10-30');

        app(AccessContext::class)->forUser($this->admin);

        $board = Livewire::actingAs($this->admin)
            ->test(Board::class, ['tracker' => $this->tracker->public_id])
            ->set('search', 'Firewall');

        expect($board->get('schedule')['rows']->map(fn ($r) => $r['project']->name)->all())
            ->toBe(['Firewall audit']);
    });

    it('shows no strip when no step on this board is watched', function () {
        app(StepService::class)->setShowInSummary($this->steps['In Progress'], false, $this->admin);

        app(AccessContext::class)->forUser($this->admin);

        Livewire::actingAs($this->admin)
            ->test(Board::class, ['tracker' => $this->tracker->public_id])
            ->assertDontSee('Show dates');
    });
});
