<?php

use App\Http\Controllers\Admin\StepController;
use App\Http\Controllers\Admin\TrackerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\BreakGlassController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Livewire\ActivityLog;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Route names matter here beyond convenience: EnsureAccountActive allowlists by
 * route NAME, not path pattern. A path prefix would silently widen the moment
 * someone nested a new route underneath it.
 */

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return Auth::user()->isActive()
        ? redirect()->route('board')
        : redirect()->route('auth.pending');
})->name('home');

// ── unauthenticated ─────────────────────────────────────────────────────────
Route::view('/login', 'auth.login')->name('login');

Route::controller(GoogleAuthController::class)->group(function () {
    // Throttles here limit REQUESTS. The limiter that actually protects the approval
    // queue counts account CREATIONS and lives in SignupGate, because a determined
    // signer-up with many Google accounts is not slowed by request throttling alone.
    Route::get('/auth/google/redirect', 'redirect')
        ->middleware('throttle:10,1')->name('auth.google.redirect');

    Route::get('/auth/google/callback', 'callback')
        ->middleware('throttle:20,60')->name('auth.google.callback');

    Route::post('/logout', 'logout')->name('logout');
});

Route::controller(BreakGlassController::class)->group(function () {
    Route::get('/break-glass', 'show')->name('break-glass.show');
    // NOTE: no route-level throttle. Throttling is applied inside the authenticator,
    // AFTER the password check and only on failure — a route throttle here would
    // reintroduce exactly the lockout an attacker could hold shut (auth-lifecycle-1).
    Route::post('/break-glass', 'attempt')->name('break-glass.attempt');
});

// ── authenticated but not yet approved ──────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::view('/pending', 'auth.pending')->name('auth.pending');
});

// ── approved users only ─────────────────────────────────────────────────────
// EnsureAccountActive runs globally on the web group, so these routes carry no
// per-route gate of their own. That is deliberate: Livewire funnels every component
// interaction through one shared endpoint, and a per-route gate would leave it
// unprotected in a way page-level route tests structurally cannot detect.
Route::middleware('auth')->group(function () {
    Route::get('/board', Board::class)->name('board');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // FR-9 — readable by every approved user, and tracker-scoped by the model's own
    // global scope rather than by a gate here. The admin-only half of the screen
    // (audit_logs) checks audit.view inside the component, because the two halves
    // have genuinely different audiences and one route cannot express both.
    Route::get('/activity', ActivityLog::class)->name('activity');

    // FR-6.4 — mints a short-lived signed R2 URL after re-checking the policy, then
    // redirects. Throttled because it is the one route that hands out capability
    // tokens, and an authenticated account enumerating public_ids should be slowed.
    Route::get('/attachments/{attachment}', AttachmentController::class)
        ->middleware('throttle:60,1')
        ->name('attachments.download');
});

// ── administration ──────────────────────────────────────────────────────────
// `can:users.manage` resolves through CapabilityMatrix, so the route gate and the
// UI affordances cannot disagree about who may be here.
Route::middleware(['auth', 'can:users.manage'])
    ->prefix('admin')->name('admin.')
    ->controller(UserController::class)
    ->group(function () {
        Route::get('/users', 'index')->name('users.index');
        Route::post('/users/{user}/approve', 'approve')->name('users.approve');
        Route::post('/users/{user}/reject', 'reject')->name('users.reject');
        Route::post('/users/{user}/suspend', 'suspend')->name('users.suspend');
        Route::post('/users/{user}/reactivate', 'reactivate')->name('users.reactivate');
        Route::post('/users/{user}/role', 'setRole')->name('users.role');
    });

Route::middleware(['auth', 'can:tracker.create'])
    ->prefix('admin')->name('admin.')
    ->controller(TrackerController::class)
    ->group(function () {
        Route::get('/trackers', 'index')->name('trackers.index');
        Route::post('/trackers', 'store')->name('trackers.store');
        Route::post('/trackers/{tracker}/archive', 'archive')->name('trackers.archive');
        Route::post('/trackers/{tracker}/members', 'addMember')->name('trackers.members.add');
        Route::delete('/trackers/{tracker}/members/{user}', 'removeMember')->name('trackers.members.remove');
    });

// Steps carry their own capability rather than riding on tracker.create, so the
// route gate and CapabilityMatrix agree about what "configure steps" means.
Route::middleware(['auth', 'can:tracker.steps.configure'])
    ->prefix('admin')->name('admin.')
    ->group(function () {
        Route::post('/trackers/{tracker}/steps', [StepController::class, 'store'])->name('trackers.steps.store');
        Route::post('/trackers/{tracker}/steps/{step}/move', [StepController::class, 'move'])->name('trackers.steps.move');
        Route::post('/trackers/{tracker}/steps/{step}/rename', [StepController::class, 'rename'])->name('trackers.steps.rename');
        Route::post('/trackers/{tracker}/steps/{step}/gate', [StepController::class, 'gate'])->name('trackers.steps.gate');
        Route::post('/trackers/{tracker}/steps/{step}/retype', [StepController::class, 'retype'])->name('trackers.steps.retype');
    });
