<?php

use Illuminate\Support\Facades\DB;

/**
 * The isolation controls that no single file can enforce.
 *
 * VERIFICATION.md's authorization review makes one claim above all others — "isolation
 * is a property of the architecture, not a rule developers must remember" — and then
 * demolishes it: `exists:` validation, raw query builders and `withoutGlobalScope()` are
 * three ways out of the tracker scope that no amount of care inside the scoped models
 * can close. Its prescribed fix for authorization-5 is explicit: *"Ban bare
 * exists:/unique: on any tracker-owned table via arch test."*
 *
 * These tests are that ban. They are deliberately about SHAPE rather than behaviour:
 * every finding below is currently closed in the code, and each test exists so that
 * closing it again is not left to whoever writes the next FormRequest.
 *
 * The tracker-owned table list is read from information_schema rather than hardcoded, so
 * a table added next month is covered the day its migration runs — a hardcoded list would
 * be a control that quietly stops covering the newest, least-reviewed code.
 */

/** @return array<int,string> every table carrying a tracker_id column */
function trackerOwnedTables(): array
{
    return array_column(DB::select(
        "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tracker_id' ORDER BY 1"
    ), 't');
}

/** @return array<string,string> relative path => source */
function applicationSources(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files['app/'.str_replace(base_path('app').'/', '', $file->getPathname())] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never validates a tracker-owned table with a string-form exists or unique rule', function () {
    // ═══════════════════════════════════════════════════════════════════════════════
    // VERIFICATION.md authorization-5 · CRITICAL.
    //
    // `exists:` runs through DatabasePresenceVerifier, which uses DB::table()
    // internally — so it bypasses the Eloquent global scope AND any architecture test
    // that bans DB::table() in application code, because the call lives in the
    // framework. A Member of tracker A posting a step_id guessed from tracker B gets
    // "not authorized"; a genuinely absent id gets "the selected step id is invalid".
    // Those two responses are a boolean oracle over trackers they cannot see, and no
    // route-parameter isolation sweep catches it because the id never touches the URL.
    //
    // The string form is banned outright rather than inspected, because unlike
    // Rule::exists() it has NOWHERE to put a tracker constraint: `'exists:steps,id'`
    // cannot be scoped, only replaced.
    // ═══════════════════════════════════════════════════════════════════════════════
    $owned = trackerOwnedTables();
    $violations = [];

    foreach (applicationSources() as $path => $source) {
        preg_match_all('/[\'"](?:exists|unique):\s*([a-z_]+)/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (in_array($match[1], $owned, true)) {
                $violations[] = "{$path} — '{$match[0]}'";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'A tracker-owned table is validated with a rule that cannot carry a tracker constraint:',
        ...$violations,
        '',
        'Use App\Rules\ScopedExists, which fails identically for "exists in a tracker you',
        'cannot see" and "does not exist", so no existence oracle survives.',
    ]));
});

it('constrains every fluent exists or unique rule on a tracker-owned table', function () {
    // The fluent form CAN be scoped — Rule::unique('steps','name')->where(fn ($q) =>
    // $q->where('tracker_id', ...)) is correct and is what StepController does. So this
    // asserts the constraint is present rather than banning the form.
    //
    // Honest about what it is: a proximity heuristic, not a proof. It reads the rule
    // expression and requires it to mention tracker_id. It cannot tell a real constraint
    // from the word appearing in a comment — it is a prompt to think at review time, and
    // the policy re-assertion in the service layer remains the actual control.
    $owned = trackerOwnedTables();
    $violations = [];

    foreach (applicationSources() as $path => $source) {
        preg_match_all('/Rule::(?:exists|unique)\(\s*[\'"]([a-z_]+)[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (! in_array($match[1][0], $owned, true)) {
                continue;
            }

            // The rule expression, generously bounded: long enough to cover a chained
            // ->ignore()->where(...) across several lines.
            $expression = substr($source, $match[0][1], 400);

            if (! str_contains($expression, 'tracker_id')) {
                $violations[] = "{$path} — Rule::…('{$match[1][0]}') with no tracker_id constraint";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'A fluent validation rule reads a tracker-owned table without constraining it:',
        ...$violations,
        '',
        'Chain ->where(fn ($q) => $q->where(\'tracker_id\', $tracker->id)), or use ScopedExists.',
    ]));
});

it('has no global-scope bypass anywhere in application code', function () {
    // AUTHZ-16 sold the bypass surface as "two enumerable call sites"; the reviewer
    // found at least six paths out and predicted the third would be added under time
    // pressure to fix a bootstrapping recursion, by whoever was least able to judge it.
    //
    // The count is currently ZERO, and pinning it there is the cheapest possible moment
    // to have this argument: the first withoutGlobalScope() in the codebase should cost
    // someone a failing test and a decision, not a quiet commit. SystemContext::run()
    // and UserContext::runAs() are the sanctioned bypasses, and both are auditable
    // because both are named.
    $violations = [];

    foreach (applicationSources() as $path => $source) {
        if (preg_match('/withoutGlobalScopes?\s*\(/', $source)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'These files bypass the tracker visibility scope directly:',
        ...$violations,
        '',
        'Bind a context instead — SystemContext::run() for tracker-agnostic background',
        'work, UserContext::runAs($user) for anything done on one person\'s behalf.',
    ]));
});

it('keeps raw reads of tracker-owned tables inside the files that justify them', function () {
    // ═══════════════════════════════════════════════════════════════════════════════
    // authorization-4 / authorization-5, the "at least six paths out" claim.
    //
    // DB::table() returns an Illuminate query builder, which carries no global scope and
    // — unlike Eloquent — does NOT nest caller-appended conditions. That second half is
    // the sharp edge: Eloquent's callScope() measures the original where count and calls
    // nestWheresForScope(), so an orWhere added by a caller cannot dissolve the tracker
    // predicate. On a raw builder it silently can, turning AND tracker_id IN (...) into
    // an optional branch of a disjunction.
    //
    // Every file below reads a tracker-owned table raw for a reason recorded in the file
    // itself: pivot maintenance with no model, or system-context work that re-asserts
    // authorization explicitly. This is an ALLOWLIST, not an approval — it exists so
    // that the seventh path out is a decision someone makes on purpose.
    // ═══════════════════════════════════════════════════════════════════════════════
    $allowed = [
        'app/Livewire/ActivityLog.php',
        'app/Services/Auth/AuditLogger.php',
        'app/Services/Notifications/OutboxDispatcher.php',
        'app/Services/Notifications/OutboxWriter.php',
        'app/Services/Projects/ActivityRecorder.php',
        'app/Services/Projects/CommentService.php',
        'app/Services/Projects/WatcherService.php',
        'app/Services/Trackers/TrackerService.php',

        // These three read `tracker_members` as a SUBQUERY to answer "who is on this
        // tracker" for an owner/assignee picker. The subquery is constrained by a
        // tracker id that came from a scoped read, so it can only ever enumerate a
        // tracker the viewer can already see.
        'app/Livewire/Board.php',
        'app/Livewire/ProjectDrawer.php',
        'app/Livewire/ProjectModal.php',
    ];

    $owned = trackerOwnedTables();
    $violations = [];

    foreach (applicationSources() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        // `->from('table')` counts too. A subquery closure reaches the same builder by a
        // different spelling, and a control that only knows one of them would report a
        // clean bill of health while the other spread.
        preg_match_all('/(?:DB::table|->from)\(\s*[\'"]([a-z_]+)[\'"]/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (in_array($match[1], $owned, true)) {
                $violations[] = "{$path} — DB::table('{$match[1]}')";
            }
        }
    }

    expect($violations)->toBe([], implode("\n", [
        'A tracker-owned table is read through a raw builder outside the allowlist:',
        ...$violations,
        '',
        'Prefer the Eloquent model, which carries TrackerVisibilityScope and nests any',
        'orWhere a caller appends. If a raw read is genuinely necessary, add the file to',
        'the allowlist in this test with a comment saying what re-asserts authorization.',
    ]));
});
