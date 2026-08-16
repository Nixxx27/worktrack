<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Livewire\ProjectModal;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\TrackerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The add/edit-a-project dialog and the service under it.
 *
 * HARNESS NOTE, same as BoardTest: Livewire::test() does not run the web middleware,
 * so the access context must be bound explicitly or the component tests visibility
 * that production never computes.
 */
function modalAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user);
}

beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $svc = app(TrackerService::class);
    $this->itTracker = $svc->create(['name' => 'IT Technical'], $this->admin);
    $this->sdTracker = $svc->create(['name' => 'Systems Development'], $this->admin);

    $this->member = makeUser('joy@gmail.com', UserRole::Member);
    $this->colleague = makeUser('rico@gmail.com', UserRole::Member);
    $this->viewer = makeUser('ella@gmail.com', UserRole::Viewer);
    $this->outsider = makeUser('sam@gmail.com', UserRole::Member);

    foreach ([$this->member, $this->colleague, $this->viewer] as $u) {
        $svc->addMember($this->itTracker, $u, $this->admin);
    }
    $svc->addMember($this->sdTracker, $this->outsider, $this->admin);
});

describe('the dialog collects what the form requires', function () {

    it('creates a project with title, dates, owner, assignees and tags', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', 'Branch VPN failover')
            ->set('description', 'Cutover the Cebu branch to the secondary tunnel.')
            ->set('startDate', '2026-08-14')
            ->set('dueDate', '2026-09-30')
            ->set('priority', 'high')
            ->set('ownerId', $this->colleague->id)
            ->set('assignees', [$this->member->id])
            ->set('tags', ['Network', 'vendor'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('project-saved');

        $project = Project::withoutGlobalScopes()->where('name', 'Branch VPN failover')->firstOrFail();

        expect($project->start_date->toDateString())->toBe('2026-08-14')
            ->and($project->target_date->toDateString())->toBe('2026-09-30')
            ->and($project->priority)->toBe('high')
            ->and($project->owner_user_id)->toBe($this->colleague->id);

        SystemContext::run(function () use ($project) {
            expect($project->assignees()->pluck('users.id')->all())->toBe([$this->member->id])
                ->and($project->tags()->pluck('tags.name')->sort()->values()->all())->toBe(['Network', 'vendor']);
        });
    });

    it('prefills the dates and the creator as owner, so the fast path stays fast', function () {
        // The dates are required now, and a required field that starts empty is
        // friction — which is the exact thing NFR-U1 says turns dashboards into fiction.
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->assertSet('startDate', now()->toDateString())
            ->assertSet('dueDate', now()->addWeeks(2)->toDateString())
            ->assertSet('ownerId', $this->member->id)
            ->set('name', 'Straight through')
            ->call('save')
            ->assertHasNoErrors();

        expect(Project::withoutGlobalScopes()->where('name', 'Straight through')->exists())->toBeTrue();
    });

    it('requires a title, a start date and a due date', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', '')
            ->set('startDate', '')
            ->set('dueDate', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'startDate' => 'required', 'dueDate' => 'required']);

        expect(Project::withoutGlobalScopes()->count())->toBe(0);
    });

    it('refuses a due date that falls before the start date', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', 'Backwards')
            ->set('startDate', '2026-09-30')
            ->set('dueDate', '2026-08-14')
            ->call('save')
            ->assertHasErrors(['dueDate' => 'after_or_equal']);
    });

    it('never opens for a viewer', function () {
        modalAs($this->viewer)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->assertForbidden()
            ->assertSet('open', false);
    });
});

describe('FR-4.3 — you cannot assign work to someone who cannot see it', function () {

    it('offers only active members of this tracker as owner or assignee', function () {
        $suspended = makeUser('gone@gmail.com', UserRole::Member);
        app(TrackerService::class)->addMember($this->itTracker, $suspended, $this->admin);
        SystemContext::run(fn () => $suspended->update(['status' => 'suspended']));

        $names = modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->instance()
            ->members->pluck('name');

        expect($names)->toContain($this->member->name)
            ->and($names)->toContain($this->colleague->name)
            // A member of the OTHER tracker, and a suspended member of this one.
            ->and($names)->not->toContain($this->outsider->name)
            ->and($names)->not->toContain($suspended->name);
    });

    it('rejects an owner posted from outside the tracker', function () {
        // The crafted-payload case: the dropdown never offered this id.
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', 'Crafted')
            ->set('ownerId', $this->outsider->id)
            ->call('save')
            ->assertHasErrors('ownerId');

        expect(Project::withoutGlobalScopes()->where('name', 'Crafted')->exists())->toBeFalse();
    });

    it('rejects an assignee posted from outside the tracker', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', 'Crafted assignee')
            ->set('assignees', [$this->outsider->id])
            ->call('save')
            ->assertHasErrors('assignees.*');
    });

    it('refuses at the service layer too, not only in the form', function () {
        // The form is one caller. The invariant belongs to the domain.
        expect(fn () => app(ProjectService::class)->create(
            $this->itTracker,
            ['name' => 'Bypassed', 'assignees' => [$this->outsider->id]],
            $this->member,
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('tags', function () {

    it('creates a tag once and reuses it across projects', function () {
        $svc = app(ProjectService::class);

        $a = $svc->create($this->itTracker, ['name' => 'A', 'tags' => ['network']], $this->member);
        $b = $svc->create($this->itTracker, ['name' => 'B', 'tags' => ['Network']], $this->colleague);

        SystemContext::run(function () use ($a, $b) {
            // Case-insensitive: "Network" typed today is the tag "network" from before,
            // otherwise the filter list fills with near-duplicates and filtering by one
            // of them silently under-reports.
            expect(Tag::withoutGlobalScopes()->where('tracker_id', $this->itTracker->id)->count())->toBe(1)
                ->and($a->tags()->first()->id)->toBe($b->tags()->first()->id)
                // First spelling wins, so the label already on the board does not change.
                ->and($a->tags()->first()->name)->toBe('network');
        });
    });

    it('trims, de-duplicates and drops blanks', function () {
        $project = app(ProjectService::class)->create(
            $this->itTracker,
            ['name' => 'Messy', 'tags' => ['  urgent  ', 'URGENT', '', '   ', 'q3']],
            $this->member,
        );

        SystemContext::run(fn () => expect($project->tags()->pluck('tags.name')->sort()->values()->all())
            ->toBe(['q3', 'urgent']));
    });

    it('keeps one tracker\'s tags out of another\'s autocomplete', function () {
        app(ProjectService::class)->create($this->itTracker, ['name' => 'A', 'tags' => ['firewall']], $this->member);

        // A tag name is user-authored text that often names the work. Leaking the list
        // leaks the vocabulary of a tracker the reader cannot see.
        $suggestions = modalAs($this->outsider)->test(ProjectModal::class)
            ->call('openForCreate', $this->sdTracker->public_id)
            ->instance()
            ->suggestedTags;

        expect($suggestions)->not->toContain('firewall');
    });

    it('splits a pasted comma list into chips', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('tagInput', 'urgent, vendor, q3')
            ->call('addTag')
            ->assertSet('tags', ['urgent', 'vendor', 'q3'])
            ->assertSet('tagInput', '');
    });

    it('saves a tag still sitting in the input box', function () {
        // Nobody expects a word they typed to vanish because they clicked Save
        // instead of pressing Enter.
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForCreate', $this->itTracker->public_id)
            ->set('name', 'Unflushed')
            ->set('tagInput', 'lastminute')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::withoutGlobalScopes()->where('name', 'Unflushed')->firstOrFail();

        SystemContext::run(fn () => expect($project->tags()->pluck('tags.name')->all())->toBe(['lastminute']));
    });
});

describe('editing an existing project', function () {

    beforeEach(function () {
        $this->project = app(ProjectService::class)->create(
            $this->itTracker,
            ['name' => 'Firewall upgrade', 'tags' => ['network'], 'assignees' => [$this->member->id]],
            $this->admin,
        );
    });

    it('loads the current values into the form', function () {
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForEdit', $this->project->public_id)
            ->assertSet('name', 'Firewall upgrade')
            ->assertSet('ownerId', $this->admin->id)
            ->assertSet('tags', ['network'])
            ->assertSet('assignees', [$this->member->id]);
    });

    it('recovers a missing start date but never invents a due date', function () {
        // This project was created through the one-field service path, so it has
        // neither. created_at is a fact; a due date is a commitment somebody made,
        // and a guessed one would be measured as though they had made it.
        modalAs($this->member)->test(ProjectModal::class)
            ->call('openForEdit', $this->project->public_id)
            ->assertSet('startDate', $this->project->created_at->toDateString())
            ->assertSet('dueDate', '')
            ->call('save')
            ->assertHasErrors(['dueDate' => 'required']);
    });

    it('lets any member of the tracker edit a card they do not own', function () {
        // Trello-style, settled. The owner here is the admin.
        modalAs($this->colleague)->test(ProjectModal::class)
            ->call('openForEdit', $this->project->public_id)
            ->set('name', 'Firewall upgrade (phase 2)')
            ->set('dueDate', '2026-12-31')
            ->set('ownerId', $this->colleague->id)
            ->set('assignees', [$this->colleague->id])
            ->set('tags', ['network', 'vendor'])
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $this->project->fresh();

        expect($fresh->name)->toBe('Firewall upgrade (phase 2)')
            ->and($fresh->owner_user_id)->toBe($this->colleague->id);

        SystemContext::run(fn () => expect($fresh->assignees()->pluck('users.id')->all())
            ->toBe([$this->colleague->id]));
    });

    it('cannot be opened for a project in an invisible tracker', function () {
        $foreign = SystemContext::run(fn () => app(ProjectService::class)
            ->create($this->sdTracker, ['name' => 'Payroll phase 2'], $this->admin));

        // Not found, not forbidden: a 403 would confirm the project exists.
        expect(fn () => modalAs($this->member)->test(ProjectModal::class)
            ->call('openForEdit', $foreign->public_id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('writes nothing when nothing changed', function () {
        $before = DB::table('project_activities')->where('project_id', $this->project->id)->count();
        $clock = $this->project->last_activity_at;

        $changed = app(ProjectService::class)->update($this->project, [
            'name' => 'Firewall upgrade',
            'tags' => ['network'],
            'assignees' => [$this->member->id],
        ], $this->colleague);

        expect($changed)->toBeFalse()
            ->and(DB::table('project_activities')->where('project_id', $this->project->id)->count())->toBe($before)
            // The stall clock especially: a no-op save must not make a rotting
            // project look freshly worked on.
            ->and($this->project->fresh()->last_activity_at->eq($clock))->toBeTrue();
    });
});
