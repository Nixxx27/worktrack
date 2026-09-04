<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\ProjectVisibility;
use App\Enums\StepType;
use App\Enums\UserRole;
use App\Livewire\Board;
use App\Livewire\ProjectModal;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Step;
use App\Models\Task;
use App\Models\Tracker;
use App\Models\User;
use App\Services\Notifications\OutboxWriter;
use App\Services\Projects\ProjectService;
use App\Services\Trackers\StepService;
use Livewire\Livewire;

/**
 * FR-4.11 — "Only me" cards.
 *
 * The second visibility axis, under tracker membership. TrackerIsolationTest covers
 * what a member of another tracker cannot see; this file covers what a member of the
 * SAME tracker cannot see, which is a harder question: these people share a board, a
 * step list, an activity feed and a notification fan-out, and every one of those is a
 * separate way for a hidden card to announce itself.
 *
 * The tests are organised by LEAK CHANNEL rather than by class, because that is how
 * the feature fails: not with a scope that stops working, but with a sixth surface
 * nobody attached it to.
 */
beforeEach(function () {
    $this->tracker = makeTracker('IT Technical');

    $this->nikko = makeUser('nikko@gmail.com');
    $this->colleague = makeUser('colleague@gmail.com');
    $this->admin = makeUser('admin@example.com', UserRole::Admin);

    addMember($this->tracker, $this->nikko);
    addMember($this->tracker, $this->colleague);

    $this->step = SystemContext::run(fn () => Step::create([
        'tracker_id' => $this->tracker->id,
        'name' => 'Backlog',
        'type' => StepType::Intake,
        'position' => 0,
    ]));

    // Two cards on ONE board: the shared one everybody sees, and the private one only
    // its owner does. Same tracker, same step — the point of the feature is that these
    // sit side by side rather than in separate containers.
    $this->makeCard = function (string $name, ProjectVisibility $visibility, ?User $owner = null) {
        return SystemContext::run(fn () => Project::create([
            'tracker_id' => $this->tracker->id,
            'step_id' => $this->step->id,
            'name' => $name,
            'visibility' => $visibility,
            'owner_user_id' => ($owner ?? $this->nikko)->id,
            'last_activity_at' => now(),
            'current_step_entered_at' => now(),
        ]));
    };

    $this->shared = ($this->makeCard)('Firewall upgrade', ProjectVisibility::Tracker);
    $this->secret = ($this->makeCard)('Salary review notes', ProjectVisibility::Private);
});

describe('the card itself', function () {

    it('shows a private card to its owner', function () {
        bindContextFor($this->nikko);

        expect(Project::pluck('name')->all())
            ->toBe(['Firewall upgrade', 'Salary review notes']);
    });

    it('hides it from another member of the same tracker', function () {
        bindContextFor($this->colleague);

        expect(Project::pluck('name')->all())->toBe(['Firewall upgrade']);
    });

    // ═══════════════════════════════════════════════════════════════════════════
    // THE ONE EXCEPTION TO D9, and the test that pins it.
    //
    // Admins see every tracker and every shared card on it. "Only me" is the single
    // place that stops, and it has to be tested explicitly rather than inferred from
    // the member case: seesAllTrackers() short-circuits the tracker scope entirely,
    // so an admin's query reaches the privacy predicate by a different path than
    // anyone else's. A regression here shows up for exactly one role.
    // ═══════════════════════════════════════════════════════════════════════════
    it('hides it from an ADMIN', function () {
        bindContextFor($this->admin);

        expect(Project::pluck('name')->all())->toBe(['Firewall upgrade']);
    });

    it('hides it from a guest', function () {
        bindContextFor(null);

        expect(Project::count())->toBe(0);
    });

    it('shows it to system context, so the stall detector can still flag it', function () {
        expect(SystemContext::run(fn () => Project::count()))->toBe(2);
    });

    it('does not leak a private card the viewer owns in ANOTHER tracker', function () {
        // The disjunct the scope adds is `OR owner_user_id = me`. Unnested, it would
        // attach to whatever the caller had already built and return every card this
        // user owns anywhere — the tracker predicate becoming one optional branch of a
        // disjunction. This asserts the parenthesisation, not the ownership rule.
        $other = makeTracker('Systems Development');

        $otherStep = SystemContext::run(fn () => Step::create([
            'tracker_id' => $other->id, 'name' => 'Backlog', 'type' => StepType::Intake, 'position' => 0,
        ]));

        SystemContext::run(fn () => Project::create([
            'tracker_id' => $other->id,
            'step_id' => $otherStep->id,
            'name' => 'Mine, elsewhere',
            'owner_user_id' => $this->nikko->id,
            'last_activity_at' => now(),
            'current_step_entered_at' => now(),
        ]));

        bindContextFor($this->nikko);

        expect(Project::pluck('name')->all())
            ->not->toContain('Mine, elsewhere');
    });
});

describe('the trail it leaves', function () {

    // Hiding the card while its trail stays public is not privacy — it is a card with
    // the title removed. Each of these tables is scoped by tracker_id alone, so none of
    // them are covered by the predicate on `projects`; they are the reason
    // ProjectPrivacyScope resolves per model instead of living inside Project.
    beforeEach(function () {
        SystemContext::run(function () {
            foreach ([$this->shared, $this->secret] as $project) {
                ProjectActivity::create([
                    'tracker_id' => $this->tracker->id,
                    'project_id' => $project->id,
                    'user_id' => $this->nikko->id,
                    'type' => 'field_edit',
                    'payload' => ['fields' => ['the title' => ['from' => 'x', 'to' => $project->name]]],
                    'counts_as_activity' => true,
                ]);

                Comment::create([
                    'tracker_id' => $this->tracker->id,
                    'project_id' => $project->id,
                    'user_id' => $this->nikko->id,
                    'body' => "a note on {$project->name}",
                ]);

                Task::create([
                    'tracker_id' => $this->tracker->id,
                    'project_id' => $project->id,
                    'title' => "a task on {$project->name}",
                    'created_by_user_id' => $this->nikko->id,
                ]);
            }
        });
    });

    it('hides the activity rows from another member', function () {
        bindContextFor($this->colleague);

        expect(ProjectActivity::count())->toBe(1)
            ->and(ProjectActivity::first()->project_id)->toBe($this->shared->id);
    });

    it('hides the activity rows from an admin', function () {
        bindContextFor($this->admin);

        expect(ProjectActivity::count())->toBe(1);
    });

    it('hides the comments — the text universal search reads', function () {
        bindContextFor($this->colleague);

        expect(Comment::pluck('body')->all())->toBe(['a note on Firewall upgrade']);
    });

    it('hides the checklist items', function () {
        bindContextFor($this->colleague);

        expect(Task::pluck('title')->all())->toBe(['a task on Firewall upgrade']);
    });

    it('still shows the owner their own trail', function () {
        bindContextFor($this->nikko);

        expect(ProjectActivity::count())->toBe(2)
            ->and(Comment::count())->toBe(2)
            ->and(Task::count())->toBe(2);
    });

    it('keeps writing the rows, so the history survives being published later', function () {
        // The writes are deliberately NOT suppressed: last_activity_at is what stall
        // detection reads, and the movement rows are what every metric rebuilds from.
        // A card that recorded nothing would never age and never reach its owner's own
        // watchlist — and publishing it later would leave a hole where it was hidden.
        expect(SystemContext::run(fn () => ProjectActivity::count()))->toBe(2);

        bindContextFor($this->nikko);

        app(ProjectService::class)->update(
            Project::find($this->secret->id),
            ['visibility' => ProjectVisibility::Tracker],
            $this->nikko,
        );

        bindContextFor($this->colleague);

        expect(ProjectActivity::count())->toBe(3);   // both originals, plus the change itself
    });
});

describe('notifications', function () {

    it('never mails an admin about a private card', function () {
        // The leak no scope could have closed: resolveRecipients() builds its list with
        // raw builders and a hardcoded admin merge, so a private card left alone would
        // keep mailing every admin its title, its step names and its comment excerpts.
        $recipients = SystemContext::run(fn () => recipientsFor($this->secret, actor: null));

        expect($recipients)->not->toContain($this->admin->id);
    });

    it('mails the owner, so the stall reminder still arrives', function () {
        $recipients = SystemContext::run(fn () => recipientsFor($this->secret, actor: null));

        expect($recipients)->toBe([$this->nikko->id]);
    });

    it('mails nobody at all when the owner is the one acting', function () {
        $recipients = SystemContext::run(fn () => recipientsFor($this->secret, actor: $this->nikko));

        expect($recipients)->toBe([]);
    });

    it('still mails the admins about a SHARED card', function () {
        $recipients = SystemContext::run(fn () => recipientsFor($this->shared, actor: null));

        expect($recipients)->toContain($this->admin->id);
    });
});

describe('the write paths that would publish it sideways', function () {

    it('refuses assignees on a private card', function () {
        bindContextFor($this->nikko);

        expect(fn () => app(ProjectService::class)->update(
            Project::find($this->secret->id),
            ['assignees' => [$this->colleague->id]],
            $this->nikko,
        ))->toThrow(InvalidArgumentException::class, 'cannot have assignees');
    });

    it('refuses tags, because a tag name is tracker-wide vocabulary', function () {
        // Bound as the owner rather than run in system context: changeTags reads the
        // current tag list before it writes, and that read is scoped like any other.
        bindContextFor($this->nikko);

        expect(fn () => app(ProjectService::class)->update(
            Project::find($this->secret->id),
            ['tags' => ['resignation']],
            $this->nikko,
        ))->toThrow(InvalidArgumentException::class, 'cannot carry tags');
    });

    it('refuses to move ownership, which would hide the card from everyone', function () {
        // The invariant MySQL would not enforce (errno 3823 — owner_user_id is the
        // target of an ON DELETE SET NULL, and a CHECK may not constrain it).
        expect(fn () => app(ProjectService::class)->update(
            SystemContext::run(fn () => Project::find($this->secret->id)),
            ['owner_user_id' => $this->colleague->id],
            $this->nikko,
        ))->toThrow(InvalidArgumentException::class, 'cannot change owner');
    });

    it('refuses to create one owned by somebody else', function () {
        bindContextFor($this->nikko);

        expect(fn () => app(ProjectService::class)->create(
            $this->tracker,
            ['name' => 'Not mine', 'visibility' => 'private', 'owner_user_id' => $this->colleague->id],
            $this->nikko,
        ))->toThrow(InvalidArgumentException::class, 'must be owned by the person creating it');
    });

    it('refuses an unknown visibility rather than defaulting to shared', function () {
        bindContextFor($this->nikko);

        expect(fn () => app(ProjectService::class)->create(
            $this->tracker,
            ['name' => 'Typo', 'visibility' => 'privte'],
            $this->nikko,
        ))->toThrow(InvalidArgumentException::class, 'Unknown project visibility');
    });

    it('drops watchers on the way in', function () {
        bindContextFor($this->nikko);

        $shared = Project::find($this->shared->id);

        $shared->watchers()->attach($this->colleague->id, [
            'tracker_id' => $this->tracker->id,
            'watched_at' => now(),
        ]);

        app(ProjectService::class)->update($shared, ['visibility' => 'private'], $this->nikko);

        expect($shared->watchers()->count())->toBe(0);
    });
});

describe('how the board marks one', function () {

    // Three carriers, deliberately: a lock at the title, a solid ONLY ME chip in the
    // status row, and a tinted card with a navy left edge. The tint is what finds the
    // card in peripheral vision while scanning a column; the chip is what makes the
    // meaning survive greyscale, colour-blindness and a screenshot. Neither is load
    // bearing on its own, and the assertion below is on the WORD, because that is the
    // half that has to be there for the other half to be legible.
    it('labels the card in words, not colour alone', function () {
        app(AccessContext::class)->forUser($this->nikko);

        Livewire::actingAs($this->nikko)->test(Board::class, ['tracker' => $this->tracker->public_id])
            ->assertSee('Only me')
            ->assertSee('Salary review notes');
    });

    it('shows a colleague neither the card nor the marker', function () {
        app(AccessContext::class)->forUser($this->colleague);

        Livewire::actingAs($this->colleague)->test(Board::class, ['tracker' => $this->tracker->public_id])
            ->assertDontSee('Salary review notes')
            ->assertDontSee('Only me')
            ->assertSee('Firewall upgrade');
    });
});

describe('the dialog', function () {

    // Livewire::test() does not run the web middleware, so the context has to be bound
    // explicitly — same harness note as ProjectCreationTest and BoardTest.
    beforeEach(function () {
        app(AccessContext::class)->forUser($this->nikko);
    });

    it('creates a private card from the checkbox alone', function () {
        Livewire::actingAs($this->nikko)->test(ProjectModal::class)
            ->call('openForCreate', $this->tracker->public_id)
            ->set('name', 'My own notes')
            ->set('onlyMe', true)
            ->call('save')
            ->assertHasNoErrors();

        $created = SystemContext::run(fn () => Project::where('name', 'My own notes')->firstOrFail());

        expect($created->visibility)->toBe(ProjectVisibility::Private)
            ->and($created->owner_user_id)->toBe($this->nikko->id);
    });

    it('takes the assignees, tags and owner with it when ticked', function () {
        // The two lists are cleared as the box is ticked rather than at save, so they
        // leave the screen with the controls that offered them. Ownership follows for
        // the same reason: "Only me" plus somebody else's name is a contradiction, and
        // fixing it here beats a validation message about a hidden select.
        Livewire::actingAs($this->nikko)->test(ProjectModal::class)
            ->call('openForCreate', $this->tracker->public_id)
            ->set('name', 'Turning private')
            ->set('ownerId', $this->colleague->id)
            ->set('assignees', [$this->colleague->id])
            ->set('tags', ['vendor'])
            ->set('onlyMe', true)
            ->assertSet('assignees', [])
            ->assertSet('tags', [])
            ->assertSet('ownerId', $this->nikko->id)
            ->call('save')
            ->assertHasNoErrors();

        $created = SystemContext::run(fn () => Project::where('name', 'Turning private')->firstOrFail());

        SystemContext::run(function () use ($created) {
            expect($created->assignees()->count())->toBe(0)
                ->and($created->tags()->count())->toBe(0);
        });
    });

    it('refuses a private card posted with somebody else as owner', function () {
        // The select is disabled in the markup, which is presentation only — this form
        // is posted by a browser, and the property is what arrives.
        Livewire::actingAs($this->nikko)->test(ProjectModal::class)
            ->call('openForCreate', $this->tracker->public_id)
            ->set('name', 'Crafted')
            ->set('onlyMe', true)
            ->set('ownerId', $this->colleague->id)
            ->call('save')
            ->assertHasErrors(['ownerId']);

        expect(SystemContext::run(fn () => Project::where('name', 'Crafted')->exists()))->toBeFalse();
    });

    it('publishes one again by unticking the box', function () {
        app(AccessContext::class)->forUser($this->nikko);

        Livewire::actingAs($this->nikko)->test(ProjectModal::class)
            ->call('openForEdit', $this->secret->public_id)
            ->assertSet('onlyMe', true)
            ->set('onlyMe', false)
            // The fixture predates due dates, which the edit form requires of every card
            // it touches — nothing to do with privacy, but the save fails without it.
            ->set('dueDate', now()->addWeek()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        bindContextFor($this->colleague);

        expect(Project::pluck('name')->all())
            ->toContain('Salary review notes');
    });
});

describe('writes that must still reach a hidden card', function () {

    it('restamps a private card when an admin retypes its column', function () {
        // A REGRESSION THIS FEATURE CAUSED, caught before it shipped. The restamp read
        // was `Project::where('step_id', ...)` on the premise that anyone who may
        // configure a tracker's steps can see its cards. Privacy broke that premise: the
        // scoped UPDATE skipped other members' private cards and left current_step_type
        // — a denormalized column only the recorders may write — disagreeing forever
        // with the column the card sits in, on cards nobody can see to notice.
        // Bound as the admin who would be doing this: retype() reads the tracker's other
        // steps to check FR-3.3, and those reads are scoped like any other.
        bindContextFor($this->admin);

        app(StepService::class)->retype(
            Step::find($this->step->id),
            StepType::Active,
            $this->admin,
        );

        $secret = SystemContext::run(fn () => Project::find($this->secret->id));

        expect($secret->current_step_type)->toBe(StepType::Active);
    });

    it('counts a private card in the blast radius an admin is shown', function () {
        expect(app(StepService::class)->countCardsIn(
            SystemContext::run(fn () => Step::find($this->step->id))
        ))->toBe(2);
    });

    it('does not stack a new card on top of a private one', function () {
        // nextPosition() reads MAX(board_position) privacy-blind. Scoped, a colleague's
        // new card would take the position a private card already holds, and the one
        // person who can see both would get an order that flips between requests.
        bindContextFor($this->colleague);

        $created = app(ProjectService::class)->create(
            SystemContext::run(fn () => Tracker::find($this->tracker->id)),
            ['name' => 'Theirs'],
            $this->colleague,
        );

        $secret = SystemContext::run(fn () => Project::find($this->secret->id));

        expect((float) $created->board_position)->toBeGreaterThan((float) $secret->board_position);
    });
});

describe('metrics', function () {

    it('leaves a private card out of the aggregates, for its owner too', function () {
        // Not fastidiousness: these results are cached under AccessContext::cacheKey(),
        // which distinguishes viewers by tracker set ALONE. If the owner's own private
        // work entered a metric, the first member to warm the cache would serve their
        // private figures to every colleague sharing their trackers.
        bindContextFor($this->nikko);

        expect(Project::excludingPrivate()->pluck('name')->all())
            ->toBe(['Firewall upgrade']);
    });
});

/**
 * The recipient ids OutboxWriter would fan a step change out to.
 *
 * Reaches through the private method deliberately: the alternative is asserting on
 * rows in notification_outbox, which would make every one of these tests a test of
 * the digest merger and the mute table as well. What is under test here is the
 * RECIPIENT LIST, and that is exactly the method that computes it.
 *
 * @return list<int>
 */
function recipientsFor(Project $project, ?User $actor): array
{
    $writer = app(OutboxWriter::class);

    $resolve = new ReflectionMethod($writer, 'resolveRecipients');

    return collect($resolve->invoke($writer, $project, $actor))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->sort()
        ->values()
        ->all();
}
