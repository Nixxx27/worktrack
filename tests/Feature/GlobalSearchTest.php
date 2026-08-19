<?php

use App\Authorization\AccessContext;
use App\Enums\UserRole;
use App\Livewire\GlobalSearch;
use App\Models\Comment;
use App\Models\User;
use App\Services\Projects\CommentService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TaskService;
use App\Services\Trackers\TrackerService;
use Livewire\Livewire;

/**
 * FR-4.10 — universal search.
 *
 * The sharp tests are in the second block, and they are sharper than the board
 * filter's equivalents because this search reaches into three related tables. Inside a
 * whereHas closure, an unnested OR does not merely widen the result set — it dissolves
 * the EXISTS correlation, so ONE matching comment anywhere in the database satisfies
 * the subquery for EVERY project. The failure looks like "search returns the whole
 * board", and no test that only checks a happy-path match will see it.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $trackers = app(TrackerService::class);

    $this->it = $trackers->create(['name' => 'IT Department'], $this->admin);
    $this->dev = $trackers->create(['name' => 'Dev Team'], $this->admin);
    $this->hidden = $trackers->create(['name' => 'Executive'], $this->admin);

    // Member of two of the three. The third is the control for every isolation claim
    // below: it must never appear, and must never make anything else appear either.
    $this->joy = makeUser('joy@gmail.com', UserRole::Member);
    $trackers->addMember($this->it, $this->joy, $this->admin);
    $trackers->addMember($this->dev, $this->joy, $this->admin);

    $projects = app(ProjectService::class);

    $this->cctv = $projects->create($this->it, [
        'name' => 'Ground-level CCTV Installation',
        'description' => 'Cameras for the 2293 parking area',
    ], $this->admin);

    $this->thrifty = $projects->create($this->dev, [
        'name' => 'Revamp Thrifty Website',
    ], $this->admin);

    $this->carwash = $projects->create($this->it, [
        'name' => 'Carwash System',
    ], $this->admin);
});

/**
 * Binds the access context as well as the auth user, so the component computes the same
 * visibility production would. Named for this file rather than reusing BoardTest's
 * asUser(), because Pest loads every feature file into one process and two global
 * helpers of the same name is a collision that only appears in a full-suite run.
 */
function searchAs(User $user)
{
    app(AccessContext::class)->forUser($user);

    return Livewire::actingAs($user)->test(GlobalSearch::class);
}

describe('finding work across trackers', function () {
    it('finds cards on every tracker the viewer belongs to, in one search', function () {
        // The gap this feature exists to close: the board filter would need two visits
        // and the term retyped to answer this.
        searchAs($this->joy)->set('term', 'a')->set('term', 'e')
            ->set('term', 'CCTV')
            ->assertSee('Ground-level CCTV Installation')
            ->assertSee('IT Department');

        searchAs($this->joy)->set('term', 'Thrifty')
            ->assertSee('Revamp Thrifty Website')
            ->assertSee('Dev Team');
    });

    it('names which board and column a result is on', function () {
        // Across trackers a bare step name is ambiguous — two trackers may each have an
        // "In Progress" meaning different things — so the tracker has to be named too.
        searchAs($this->joy)->set('term', 'Carwash')
            ->assertSee('Carwash System')
            ->assertSee('IT Department')
            ->assertSee('Backlog');
    });

    it('matches on the description and says where the match was', function () {
        searchAs($this->joy)->set('term', 'parking area')
            ->assertSee('Ground-level CCTV Installation')
            ->assertSee('matched in the description');
    });

    it('matches on a label and says where the match was', function () {
        app(ProjectService::class)->update($this->carwash, ['tags' => ['Installation']], $this->admin);

        searchAs($this->joy)->set('term', 'Installat')
            ->assertSee('Carwash System')
            ->assertSee('matched in a label');
    });

    it('matches on a checklist item and says where the match was', function () {
        app(TaskService::class)->create($this->carwash, ['title' => 'Order the pressure pump'], $this->admin);

        searchAs($this->joy)->set('term', 'pressure pump')
            ->assertSee('Carwash System')
            ->assertSee('matched in a checklist item');
    });

    it('matches on a comment and says where the match was', function () {
        // The whole argument for reaching past name and description: the vendor is what
        // the person searching remembers, and it was only ever typed into a comment.
        app(CommentService::class)->create($this->carwash, 'Quotation received from Hydroflow.', $this->admin);

        searchAs($this->joy)->set('term', 'Hydroflow')
            ->assertSee('Carwash System')
            ->assertSee('matched in a comment');
    });

    it('gives no hint when the title already shows the match', function () {
        // A hint over a visible match reads as though the title match went unnoticed.
        searchAs($this->joy)->set('term', 'Carwash')
            ->assertSee('Carwash System')
            ->assertDontSee('matched in');
    });

    it('reports the most visible place a term appears when it appears in several', function () {
        app(CommentService::class)->create($this->cctv, 'The parking area is fully wired.', $this->admin);

        // Description AND comment both match; the description is on the card face.
        searchAs($this->joy)->set('term', 'parking area')
            ->assertSee('matched in the description')
            ->assertDontSee('matched in a comment');
    });
});

describe('what it must never return', function () {
    it('never returns a card from a tracker the viewer is not a member of', function () {
        app(ProjectService::class)->create($this->hidden, [
            'name' => 'Board CCTV briefing',
            'description' => 'CCTV rollout for the executive floor',
        ], $this->admin);

        searchAs($this->joy)->set('term', 'CCTV')
            ->assertSee('Ground-level CCTV Installation')
            ->assertDontSee('Board CCTV briefing');
    });

    it('never lets a matching comment return every other card as well', function () {
        // ═══════════════════════════════════════════════════════════════════════════
        // THE TEST THIS FEATURE IS MOST LIKELY TO FAIL, and it took a deliberate
        // sabotage run to write correctly — the first version of it passed against
        // broken code.
        //
        // Laravel groups a whereHas closure's conditions, but joins that group to the
        // EXISTS correlation using the boolean of the closure's FIRST condition. Open
        // with orWhere and the correlation becomes optional (verified output):
        //
        //     EXISTS (select * from comments
        //             where (comments.project_id = projects.id OR (body LIKE ?))
        //               and comments.tracker_id in (…) and deleted_at is null)
        //
        // One comment matching anywhere then satisfies the subquery for EVERY project,
        // so searching a vendor named in a single comment returns the whole portfolio.
        //
        // WHAT THIS IS NOT: a cross-tracker leak. `tracker_id in (…)` above is the
        // scope's own condition and nestWheresForScope protects it — which is why the
        // obvious test (put the comment in an invisible tracker) passes either way and
        // proves nothing. The decoys therefore have to be cards the viewer CAN see.
        // ═══════════════════════════════════════════════════════════════════════════
        app(CommentService::class)->create($this->carwash, 'Quotation from Aardvark Supply.', $this->admin);

        searchAs($this->joy)->set('term', 'Aardvark')
            ->assertSee('Carwash System')                        // the one real match
            ->assertDontSee('Ground-level CCTV Installation')     // same tracker, no match
            ->assertDontSee('Revamp Thrifty Website');            // other visible tracker
    });

    it('never lets a matching checklist item return every other card as well', function () {
        // Same shape, second table. The three relation closures are written separately
        // and can therefore be got wrong separately.
        app(TaskService::class)->create($this->carwash, ['title' => 'Book the Pangolin unit'], $this->admin);

        searchAs($this->joy)->set('term', 'Pangolin')
            ->assertSee('Carwash System')
            ->assertDontSee('Ground-level CCTV Installation')
            ->assertDontSee('Revamp Thrifty Website');
    });

    it('never lets a matching label return every other card as well', function () {
        app(ProjectService::class)->update($this->carwash, ['tags' => ['Aardvark']], $this->admin);

        searchAs($this->joy)->set('term', 'Aardvark')
            ->assertSee('Carwash System')
            ->assertDontSee('Ground-level CCTV Installation')
            ->assertDontSee('Revamp Thrifty Website');
    });

    it('never returns a card matched through a comment in an invisible tracker', function () {
        // Kept even though the scope alone would pass it: the scope IS the only thing
        // holding this, and a test that pins it is worth having for the day someone
        // "optimises" one of the closures into a join.
        $secret = app(ProjectService::class)->create($this->hidden, ['name' => 'Executive offsite'], $this->admin);
        app(CommentService::class)->create($secret, 'Catering by Nightjar Foods.', $this->admin);

        searchAs($this->joy)->set('term', 'Nightjar')
            ->assertDontSee('Executive offsite')
            ->assertDontSee('Carwash System');
    });

    it('never returns archived cards, including ones matched through a comment', function () {
        // The archived_at predicate sits OUTSIDE the disjunction. If the OR were not
        // nested it would become an optional branch, and both of these would come back.
        $projects = app(ProjectService::class);

        $archived = $projects->create($this->it, [
            'name' => 'Old CCTV decommission',
            'description' => 'CCTV units retired last year',
        ], $this->admin);

        $archivedByComment = $projects->create($this->it, ['name' => 'Retired carwash bay'], $this->admin);
        app(CommentService::class)->create($archivedByComment, 'CCTV was removed from this bay.', $this->admin);

        $projects->archive($archived, $this->admin);
        $projects->archive($archivedByComment, $this->admin);

        searchAs($this->joy)->set('term', 'CCTV')
            ->assertSee('Ground-level CCTV Installation')
            ->assertDontSee('Old CCTV decommission')
            ->assertDontSee('Retired carwash bay');
    });

    it('never resurfaces a card through a comment its author deleted', function () {
        $comment = app(CommentService::class)->create($this->carwash, 'Quote from Hydroflow.', $this->admin);
        app(CommentService::class)->delete($comment, $this->admin);

        // Soft-deleted, so the row is still there — the relation has to be the thing
        // that excludes it, and it is only excluded because Comment uses SoftDeletes.
        expect(Comment::withTrashed()->find($comment->id)->trashed())->toBeTrue();

        searchAs($this->joy)->set('term', 'Hydroflow')
            ->assertDontSee('Carwash System');
    });

    it('treats LIKE wildcards as literal text', function () {
        // An unescaped `_` matches any single character and `%` matches everything, so
        // without escaping this search returns the entire portfolio.
        searchAs($this->joy)->set('term', '%')
            ->assertDontSee('Carwash System')
            ->assertDontSee('Ground-level CCTV Installation');

        searchAs($this->joy)->set('term', 'C_rwash')
            ->assertDontSee('Carwash System');
    });
});

describe('the palette itself', function () {
    it('asks for a second character rather than claiming nothing matched', function () {
        // "No results" for a one-character term is a lie about the data, and one
        // character is four LIKEs and three EXISTS over unindexable leading wildcards.
        searchAs($this->joy)->set('term', 'C')
            ->assertSee('Keep typing')
            ->assertDontSee('Carwash System');
    });

    it('says nothing at all before anything is typed', function () {
        searchAs($this->joy)
            ->assertSee('Type to search every tracker you can see.')
            ->assertDontSee('Carwash System');
    });

    it('explains an empty result without implying the portfolio is empty', function () {
        searchAs($this->joy)->set('term', 'nothing whatsoever matches this')
            ->assertSee('Archived cards are not searched');
    });

    it('links every result to the board deep link rather than a client-side event', function () {
        // FR-8.11's URL, reused: the board resolves which tracker the card is on, so one
        // href crosses trackers and survives middle-click, copy-link and paste.
        searchAs($this->joy)->set('term', 'Thrifty')
            ->assertSee(route('board', ['project' => $this->thrifty->public_id]), escape: false);
    });

    it('says when the match list was cut rather than quietly truncating it', function () {
        $projects = app(ProjectService::class);

        // Thirteen matches against a twelve-row list.
        foreach (range(1, 13) as $n) {
            $projects->create($this->it, ['name' => "Sentinel camera {$n}"], $this->admin);
        }

        searchAs($this->joy)->set('term', 'Sentinel camera')
            ->assertSee('Showing the 12 most recently active matches');
    });

    it('shows every match when there are exactly as many as it will list', function () {
        // The off-by-one: a term matching exactly the limit is not truncated, and
        // deriving "truncated" from the trimmed list alone cannot tell the difference.
        $projects = app(ProjectService::class);

        foreach (range(1, 12) as $n) {
            $projects->create($this->it, ['name' => "Sentinel camera {$n}"], $this->admin);
        }

        searchAs($this->joy)->set('term', 'Sentinel camera')
            ->assertDontSee('Showing the 12 most recently active matches');
    });

    it('empties the term when cleared', function () {
        searchAs($this->joy)->set('term', 'Carwash')
            ->assertSee('Carwash System')
            ->call('clear')
            ->assertSet('term', '')
            ->assertDontSee('Carwash System');
    });
});

describe('placement', function () {
    it('is in the header of every signed-in screen', function () {
        // Mounted once in x-app-nav rather than per page: five hand-placed copies is how
        // a control ends up missing from one screen.
        foreach (['board', 'dashboard', 'activity'] as $route) {
            $this->actingAs($this->joy)->get(route($route))
                ->assertOk()
                ->assertSee('Search all trackers');
        }
    });

    it('is not on the signed-out pages', function () {
        $this->get(route('login'))->assertOk()->assertDontSee('Search all trackers');
    });
});
