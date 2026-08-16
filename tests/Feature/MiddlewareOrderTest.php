<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EstablishAccessContext;
use App\Models\Step;
use App\Services\Trackers\TrackerService;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;

/**
 * The web middleware group's order, and the bug it hid.
 *
 * Route-model binding is a scoped query. Resolving {tracker} runs
 * Tracker::where('public_id', …) through TrackerVisibilityScope, so if
 * SubstituteBindings runs before EstablishAccessContext the scope finds no context,
 * fails closed as designed, and the request 500s with MissingAccessContextException.
 * That was the state of every {tracker} route until this test existed.
 *
 * EVERY test here resets the context first. Without that they prove nothing: the
 * setup below binds a context directly, and that binding is still in the container
 * when the test's HTTP request runs, so the request never has to establish its own.
 */
beforeEach(function () {
    $this->admin = makeUser('admin@example.com', UserRole::Admin);
    bindContextFor($this->admin);

    $this->tracker = app(TrackerService::class)->create(['name' => 'IT Technical'], $this->admin);
    $this->member = makeUser('joy@gmail.com', UserRole::Member);
});

it('binds the access context before route-model binding resolves a scoped model', function () {
    $web = app('router')->getMiddlewareGroups()['web'];

    $context = array_search(EstablishAccessContext::class, $web, true);
    $bindings = array_search(SubstituteBindings::class, $web, true);

    expect($context)->not->toBeFalse('EstablishAccessContext is not in the web group')
        ->and($bindings)->not->toBeFalse('SubstituteBindings is not in the web group')
        ->and($context)->toBeLessThan($bindings);
});

it('checks the account is active before route-model binding, so a suspended user is redirected rather than 404d', function () {
    $web = app('router')->getMiddlewareGroups()['web'];

    expect(array_search(EnsureAccountActive::class, $web, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $web, true));
});

it('starts the session before binding the context, so the request has a user to bind', function () {
    $web = app('router')->getMiddlewareGroups()['web'];

    expect(array_search(StartSession::class, $web, true))
        ->toBeLessThan(array_search(EstablishAccessContext::class, $web, true));
});

/**
 * The behavioural half. Each of these resolves a {tracker} route parameter, which is
 * the exact operation that was throwing.
 */
describe('routes that bind a tracker survive a cold context', function () {

    it('adds a step', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.steps.store', $this->tracker), [
                'name' => 'Testing',
                'type' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        bindContextFor($this->admin);
        expect(Step::where('tracker_id', $this->tracker->id)->where('name', 'Testing')->exists())->toBeTrue();
    });

    it('adds a member', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.members.add', $this->tracker), ['user_id' => $this->member->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    it('removes a member', function () {
        app(TrackerService::class)->addMember($this->tracker, $this->member, $this->admin);
        resetContext();

        $this->actingAs($this->admin)
            ->delete(route('admin.trackers.members.remove', [$this->tracker, $this->member]))
            ->assertRedirect();
    });

    it('archives a tracker', function () {
        resetContext();

        $this->actingAs($this->admin)
            ->post(route('admin.trackers.archive', $this->tracker))
            ->assertRedirect();
    });

    it('renders the board', function () {
        resetContext();

        $this->actingAs($this->admin)->get(route('board'))->assertOk();
    });

    it('renders the tracker admin screen', function () {
        resetContext();

        $this->actingAs($this->admin)->get(route('admin.trackers.index'))->assertOk();
    });
});

it('sends a suspended admin to the pending screen instead of resolving the tracker', function () {
    $suspended = makeUser('gone@example.com', UserRole::Admin, UserStatus::Suspended);
    resetContext();

    $this->actingAs($suspended)
        ->post(route('admin.trackers.steps.store', $this->tracker), ['name' => 'Testing', 'type' => 'active'])
        ->assertRedirect(route('auth.pending'));

    bindContextFor($this->admin);
    expect(Step::where('tracker_id', $this->tracker->id)->where('name', 'Testing')->exists())->toBeFalse();
});
