<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EstablishAccessContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Both are appended to the GLOBAL web group, deliberately never as per-route
        // aliases. Livewire drives every component interaction through one shared
        // update endpoint, so a per-route gate would leave that endpoint unprotected
        // and unprotectable by page-level route tests — an FR-1.3 hole invisible to
        // exactly the tests you would write to look for it.
        //
        // Order matters: the context must be bound before anything queries a scoped
        // model, and the active check must run before any controller does work.
        //
        // ═══════════════════════════════════════════════════════════════════════════
        // SubstituteBindings is pulled out of its default slot and re-added AFTER
        // these two. Appending alone was not enough, and the gap was a live 500.
        //
        // Route-model binding IS "anything queries a scoped model": resolving
        // {tracker} runs Tracker::where('public_id', …) through
        // TrackerVisibilityScope. In the default order SubstituteBindings sits at
        // position 5 and EstablishAccessContext at 6, so that query ran with no
        // context bound and the scope — correctly failing closed — threw
        // MissingAccessContextException. Every route with a {tracker} parameter
        // (archive, add/remove member, add step) was a guaranteed 500 in the browser.
        //
        // Feature tests did not catch it because they bind a context directly in
        // their setup, and that binding survives into the test's HTTP call. A test
        // must reset the context first to exercise the real cold-start path — see
        // MiddlewareOrderTest.
        // ═══════════════════════════════════════════════════════════════════════════
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                EstablishAccessContext::class,
                EnsureAccountActive::class,
                SubstituteBindings::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
