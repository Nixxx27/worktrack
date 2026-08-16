<?php

use App\Authorization\AccessContext;
use App\Authorization\SystemContext;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Tracker;
use App\Models\TrackerMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Forget whatever AccessContext the test setup bound.
 *
 * Required before any assertion about a real HTTP request. Test setup binds a
 * context directly and that binding survives into $this->get()/$this->post(), so a
 * request whose middleware never establishes one still finds a usable context and
 * passes. That masking hid a live 500 on every route with a {tracker} parameter —
 * see MiddlewareOrderTest.
 */
function resetContext(): void
{
    app(AccessContext::class)->reset();
}

/*
 * Fixture builders, HERE rather than in a test file.
 *
 * These four lived in TrackerIsolationTest, which meant the thirteen files that call
 * them could only run as part of a full-suite run: `php artisan test
 * tests/Feature/BoardTest.php` on its own died with "undefined function makeUser()".
 * Pest loads this file for every run, so each feature file is now independently
 * runnable — which is what you want while iterating on one of them.
 */

function makeUser(string $email, UserRole $role = UserRole::Member, UserStatus $status = UserStatus::Active): User
{
    return User::create([
        'name' => ucfirst(explode('@', $email)[0]),
        'email' => $email,
        'auth_provider' => 'google',
        'role' => $role,
        'status' => $status,
        'timezone' => 'Asia/Manila',
    ]);
}

function makeTracker(string $name): Tracker
{
    // Created inside system context because Tracker is self-scoped: without a
    // context bound, even the create()->fresh() read would fail closed.
    return SystemContext::run(fn () => Tracker::create(['name' => $name]));
}

function addMember(Tracker $tracker, User $user): void
{
    TrackerMember::create([
        'tracker_id' => $tracker->id,
        'user_id' => $user->id,
        'added_at' => now(),
    ]);
}

function bindContextFor(?User $user): AccessContext
{
    /** @var AccessContext $context */
    $context = app(AccessContext::class);
    $user ? $context->forUser($user) : $context->guest();

    return $context;
}
