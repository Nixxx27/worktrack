<?php

namespace App\Rules;

use App\Authorization\AccessContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * The tracker-scoped replacement for Laravel's `exists:` rule.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * CRITICAL FIX — VERIFICATION.md authorization-5.
 *
 * A large share of identifiers arrive in request BODIES, not route parameters, and
 * `exists:` executes through DatabasePresenceVerifier, which uses DB::table()
 * internally. That bypasses the Eloquent global scope entirely — and also bypasses
 * any architecture test banning DB::table(), because the call lives in the
 * framework, not in application code.
 *
 * The result is an existence oracle. A Member of tracker A posts to the drop
 * endpoint with a step_id guessed from tracker B. Bare `exists:steps,id` passes, so
 * the request proceeds to a 403 from the domain layer — while a genuinely
 * non-existent id returns "The selected step id is invalid." Those two different
 * responses are a boolean oracle, enumerable in a few hundred requests, revealing
 * the size and step structure of trackers the attacker cannot see. Route-parameter
 * isolation sweeps never catch it, because they only substitute ids into the URL.
 *
 * The second half is worse than disclosure: the same shape lets a crafted POST set
 * assignees[] to a user who belongs only to tracker B, violating FR-4.3 in the
 * database. That row is poison — if that user is ever legitimately added to tracker
 * A, they silently inherit edit and move rights on a project nobody granted them,
 * because ownership is defined as "appears in project_assignees".
 *
 * This rule fails IDENTICALLY for "exists in a tracker you cannot see" and "does
 * not exist", so no oracle survives.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class ScopedExists implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var AccessContext $context */
        $context = app(AccessContext::class);

        $query = DB::table($this->table)->where($this->column, $value);

        // Admins and system context skip the tracker filter but still require the
        // row to exist.
        if (! $context->seesAllTrackers()) {
            $visible = $context->visibleTrackerIds();

            if ($visible === []) {
                // Deliberately identical to the not-found message below.
                $fail('validation.exists')->translate(['attribute' => $attribute]);

                return;
            }

            $query->whereIn('tracker_id', $visible);
        }

        if (! $query->exists()) {
            $fail('validation.exists')->translate(['attribute' => $attribute]);
        }
    }
}
