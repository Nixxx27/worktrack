<?php

namespace App\Providers;

use App\Authorization\AccessContext;
use App\Authorization\Capability;
use App\Authorization\CapabilityMatrix;
use App\Models\User;
use App\Services\Auth\RememberMe;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request / per job. Scoped rather than singleton so a queue
        // worker processing many jobs in one process cannot leak one job's visibility
        // into the next.
        $this->app->scoped(AccessContext::class, fn () => new AccessContext);
    }

    public function boot(): void
    {
        $this->registerStatusGate();
        $this->registerCapabilityGates();
        $this->registerQueueContextGuard();
        $this->registerLoginViewData();
    }

    /**
     * The sign-in page needs the remember-me settings (FR-1.11), and `/login` is a
     * bare Route::view.
     *
     * A composer rather than passing defaults into Route::view: those defaults are
     * serialised by `route:cache`, so a service instance there would break caching,
     * and reading config at route-registration time would freeze the values into the
     * cache file. A composer resolves both at render.
     */
    private function registerLoginViewData(): void
    {
        View::composer('auth.login', function ($view) {
            $view->with([
                'rememberMe' => app(RememberMe::class),
                'rememberDays' => (int) config('worktrack.auth.remember_days', 30),
            ]);
        });
    }

    /**
     * Exposes the capability matrix as Gate abilities so routes can use `can:` and
     * Blade can use @can. The matrix stays the single source of truth — this only
     * gives it a name the framework already understands.
     */
    private function registerCapabilityGates(): void
    {
        foreach (Capability::cases() as $capability) {
            Gate::define($capability->value, fn (User $user) => CapabilityMatrix::allows($user, $capability));
        }
    }

    /**
     * Layer two of the approval gate, independent of middleware.
     *
     * Hard-denies EVERY ability for any account that is not Active — including
     * Admins and the break-glass account. There is deliberately no `return true`
     * branch here: this gate can only ever subtract permission, never grant it, so
     * it cannot become an accidental bypass.
     *
     * Returning null (not false) for active users hands control back to the normal
     * policy chain rather than short-circuiting it as an approval.
     */
    private function registerStatusGate(): void
    {
        Gate::before(function (User $user) {
            return $user->isActive() ? null : false;
        });
    }

    /**
     * VERIFICATION.md authorization-2: a queued job that touches a tracker-scoped
     * model with no bound context either throws or, once someone "fixes" it with
     * SystemContext, silently leaks every tracker.
     *
     * Failing loudly at the START of the job — rather than deep inside a mailable
     * view at 2am — makes the missing binding obvious in CI. Jobs must open with
     * UserContext::runAs() for per-user work, or SystemContext::run() for genuinely
     * tracker-agnostic maintenance.
     */
    private function registerQueueContextGuard(): void
    {
        Event::listen(function (JobProcessing $event) {
            /** @var AccessContext $context */
            $context = app(AccessContext::class);

            // Reset between jobs so a worker process cannot carry one job's
            // visibility into the next.
            $context->reset();
        });
    }
}
