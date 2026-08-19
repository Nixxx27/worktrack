<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * A term someone typed into a search box, and the ONE place a LIKE is built from one.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A CLASS AND NOT TWO LINES INLINE — VERIFICATION.md authorization-4.
 *
 * A text search is an OR, and an OR written next to an AND is the sharp edge this
 * codebase has already been cut on once. Written the obvious way:
 *
 *     ->whereNull('archived_at')->where('name', 'like', $t)->orWhere('description', 'like', $t)
 *
 * AND binds tighter than OR, so that reads "(live AND name matches) OR (description
 * matches)" — and the second branch carries no archived_at predicate at all. Every
 * archived card appears the moment someone types a term.
 *
 * Inside a whereHas closure the same mistake takes a different predicate, and which one
 * it takes turns on something nearly invisible: THE BOOLEAN OF THE FIRST CONDITION THE
 * CLOSURE ADDS. Laravel does group a whereHas closure's conditions for you — has() runs
 * it through callScope(), the same machinery global scopes ride on — but it joins that
 * group to the correlation using the first condition's boolean. A closure opening with
 * where() is therefore safe, and one opening with orWhere() is not:
 *
 *     EXISTS (select * from comments
 *             where (comments.project_id = projects.id OR (comments.body LIKE ?))
 *               and comments.tracker_id in (…) and comments.deleted_at is null)
 *
 * That is real output from this query with the nesting removed, not a sketch. The
 * correlation is now optional, so ONE matching comment anywhere the viewer can see
 * satisfies the subquery for EVERY project: searching a vendor name returns the whole
 * portfolio, every row captioned "matched in a comment".
 *
 * Note carefully what DID survive — `tracker_id in (…)` and `deleted_at is null` are the
 * scopes' own conditions, and nestWheresForScope() protects those. So this particular
 * break is a relevance catastrophe rather than a cross-tracker breach. That is a fact
 * about how much work the scope is doing underneath, not licence to lean on it: the
 * predicates written at the call site have no such protection, which is the whole reason
 * this class exists.
 *
 * matchAny() therefore always opens with $query->where(Closure) — an AND-joined nested
 * group — and uses orWhere only INSIDE it, where the boolean cannot reach the caller's
 * predicates. It is the only spelling offered, so the broken version cannot be written
 * without deliberately not using this class.
 * ═══════════════════════════════════════════════════════════════════════════════════
 */
final class SearchTerm
{
    private function __construct(
        /** What the person actually typed, trimmed — for echoing back and for matches(). */
        public readonly string $value,

        /** The bound LIKE pattern: wildcards escaped, wrapped in %…%. */
        public readonly string $pattern,
    ) {}

    /**
     * Null when there is nothing worth querying, which is what makes this safe to hand
     * straight to `when()`: a SearchTerm is truthy, null is not, so "no term" and "a
     * term too short to be useful" collapse to the same skipped clause.
     *
     * $minLength exists because the two callers want different answers. A board filter
     * is applied to cards already on screen, so one character narrowing to "everything
     * with an e" is harmless and occasionally what you meant. A cross-tracker search
     * scans four tables and returns work you cannot see, where one character is never a
     * question anyone asked and is a table scan per keystroke.
     */
    public static function from(?string $raw, int $minLength = 1): ?self
    {
        $value = trim((string) $raw);

        // mb_strlen, not strlen: a two-character search in a non-Latin script must not
        // be rejected for being "too short" because its bytes outnumber its characters.
        if ($value === '' || mb_strlen($value) < max(1, $minLength)) {
            return null;
        }

        // BACKSLASH FIRST, and the order is the whole correctness of this line. MySQL's
        // default LIKE escape character is `\`, so the escapes we are about to insert are
        // themselves written in backslashes — escaping `%` before `\` would then go back
        // and double the backslash we had just added, turning a search for a literal
        // percent sign into a search for a literal backslash followed by anything.
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);

        return new self($value, '%'.$pattern.'%');
    }

    /**
     * "Any of these columns contains the term", as one parenthesised group.
     *
     * Every branch is orWhere, including the first: on the fresh nested builder the first
     * orWhere behaves as where, and spelling one of them differently is how a column
     * later added to the front of the list silently becomes a required match.
     *
     * The where(Closure) wrapper is what makes that safe. Called on a whereHas closure's
     * builder WITHOUT it, a leading orWhere is the boolean Laravel uses to attach the
     * whole group to the EXISTS correlation — see the class comment. The wrapper is not
     * decoration around a single-column call either: `where(fn => orWhere(x))` still
     * joins to its caller with AND, which is the entire point.
     *
     * @param  list<string>  $columns  Qualified (`tasks.title`), because this is called
     *                                 inside joins and EXISTS subqueries where a bare
     *                                 `name` is ambiguous between two tables.
     */
    public function matchAny(Builder $query, array $columns): Builder
    {
        return $query->where(function (Builder $group) use ($columns) {
            foreach ($columns as $column) {
                $group->orWhere($column, 'like', $this->pattern);
            }
        });
    }

    /**
     * Does this text contain the term, as far as a human reading the screen is concerned?
     *
     * For labelling a result, never for deciding whether it is one — the database has
     * already answered that. It is deliberately an approximation: LIKE compares under
     * the column's collation, which on this schema folds case and accents, and PHP has
     * no cheap equivalent. mb_stripos folds case only.
     *
     * The consequence of the gap is a result whose hint says "matched in a comment" when
     * it also matched an accented form of the term in the title. A slightly redundant
     * hint is the failure mode; a wrong or missing result is not.
     */
    public function matches(?string $haystack): bool
    {
        return $haystack !== null && $haystack !== ''
            && mb_stripos($haystack, $this->value) !== false;
    }
}
