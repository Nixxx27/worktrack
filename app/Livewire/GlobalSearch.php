<?php

namespace App\Livewire;

use App\Models\Project;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * FR-4.10 — find a card from anywhere, across every tracker you can see.
 *
 * ── WHY THIS IS NOT A SEARCH BOX PER COLUMN ─────────────────────────────────────
 *
 * The obvious alternative was a search inside each step column. It was rejected for
 * three reasons, the third decisive:
 *
 *   1. It duplicates FR-3.6. The board filter already narrows every column at once and
 *      reports "N of M" — a per-column box is the same query asked five times, and five
 *      boxes each holding a different term is a board nobody can read the state of.
 *   2. It answers the narrower question. "Which card in To Do says CCTV" is already one
 *      glance away once the board filter is applied; "where is that CCTV thing, I think
 *      it was on another tracker" is the question with no answer today.
 *   3. It cannot be shared, and FR-3.10 already settled this exact argument for sort:
 *      per-column state means a {step: value} map in the URL, and steps are tracker-local
 *      rows with no public id, so a pasted link carries ids naming different columns —
 *      or none — on another tracker.
 *
 * ── WHAT IT SEARCHES, AND WHY MORE THAN THE BOARD DOES ──────────────────────────
 *
 * Name, description, labels, checklist items and comments. The board filter reads only
 * the two fields the card face shows, because a filter that hides things has to be
 * explicable at a glance and there is nowhere on a card to say "this stayed because of
 * a word in a comment". Here there is: every result carries a line saying where the
 * match was, so the deeper reach is legible rather than mysterious.
 *
 * That reach is where the real find rate is. A card called "2293 Wifi Authorization"
 * whose actual subject — the vendor, the ticket number, the building — was typed into a
 * comment three weeks ago is invisible to a name-and-description search, and the person
 * looking for it knows the vendor, not the title someone else chose.
 *
 * ── ISOLATION ───────────────────────────────────────────────────────────────────
 *
 * There is no where('tracker_id') in this class and there must not be: spanning trackers
 * is the entire point, so TrackerVisibilityScope is the ONLY thing standing between a
 * Member of one tracker and every card in the company. Two consequences worth naming:
 *
 *   - Eloquent only, never DB::table(). A raw builder carries no global scope and does
 *     not nest caller-appended conditions (ArchitectureInvariantsTest pins this).
 *   - Every disjunction goes through SearchTerm::matchAny(). Inside the whereHas closures
 *     below, an unnested OR dissolves the EXISTS CORRELATION — verified, and it depends
 *     on the boolean of the closure's first condition — so one matching comment anywhere
 *     visible would return every card the viewer can see, each captioned "matched in a
 *     comment". The related model's tracker predicate survives that (it is the scope's,
 *     and nestWheresForScope protects it), so the break is a relevance failure rather
 *     than a breach. See SearchTerm's class comment for the SQL.
 *
 * ── NOT IN THE QUERY STRING ─────────────────────────────────────────────────────
 *
 * Every filter on the board carries #[Url] because a filtered board is a view worth
 * handing to someone. This does not, and the difference is that the palette is not a
 * view — it is a way of leaving the one you are on. What deserves sharing is the card
 * you landed on, and that is already a URL: results are real links to /board?project=…
 * (FR-8.11), which resolves the tracker for you and survives middle-click and paste.
 */
class GlobalSearch extends Component
{
    /**
     * Two characters, not one. Every keystroke is four LIKEs and three EXISTS
     * subqueries over unindexable leading wildcards, and "everything containing an e"
     * is not a question anyone typed on purpose. The board filter keeps its
     * one-character minimum because it only narrows rows already on screen.
     */
    private const MIN_LENGTH = 2;

    /** Enough to recognise the one you meant; past this, narrow the term. */
    private const LIMIT = 12;

    /**
     * Named `term` rather than `search` so it reads differently from Board::$search in
     * a stack trace — the two are deliberately different searches, and one name for
     * both would invite someone to "unify" them.
     */
    public string $term = '';

    public function updatedTerm(): void
    {
        // The computed reads are memoized per request and would otherwise answer with
        // the previous keystroke's results.
        unset($this->results);
    }

    public function clear(): void
    {
        $this->reset('term');
        unset($this->results);
    }

    /** True once something has been typed but not yet enough to query on. */
    #[Computed]
    public function tooShort(): bool
    {
        return trim($this->term) !== '' && SearchTerm::from($this->term, self::MIN_LENGTH) === null;
    }

    /**
     * The rows, and whether the term matched more than we are showing.
     *
     * ONE computed returning both, rather than a `results` collection beside a
     * `truncated` flag. The flag cannot be derived from the collection — a term matching
     * exactly LIMIT cards is indistinguishable from one matching a thousand once the
     * list has been trimmed — and deriving it from a private property set inside
     * results() would make the answer depend on which of the two the template happened
     * to read first. A pair that can only be computed together is returned together.
     *
     * The hint is derived in PHP from the columns already loaded rather than by asking
     * the database which branch of the OR fired, because SQL cannot tell you that
     * without running the whole disjunction again per row.
     *
     * @return array{rows: Collection<int, array{project: Project, hint: ?string}>, truncated: bool}
     */
    #[Computed]
    public function results(): array
    {
        $term = SearchTerm::from($this->term, self::MIN_LENGTH);

        if (! $term) {
            return ['rows' => new Collection, 'truncated' => false];
        }

        $matched = Project::query()
            ->whereNull('archived_at')
            // ONE nested group around the whole disjunction. Without it the archived_at
            // predicate above becomes an optional branch and every archived card in every
            // visible tracker comes back the moment someone types.
            ->where(function (Builder $q) use ($term) {
                $term->matchAny($q, ['projects.name', 'projects.description']);

                // Each of these is its own EXISTS, so a card matching in three places is
                // still one row. The alternative — joining four tables — returns the same
                // card once per matching comment.
                $q->orWhereHas('tags', fn (Builder $t) => $term->matchAny($t, ['tags.name']));
                $q->orWhereHas('tasks', fn (Builder $t) => $term->matchAny($t, ['tasks.title', 'tasks.description']));
                $q->orWhereHas('comments', fn (Builder $c) => $term->matchAny($c, ['comments.body']));
            })
            // Which branch fired, for the hint. Same closures as above on purpose: a hint
            // computed from a looser predicate than the one that selected the row is a hint
            // that eventually contradicts itself.
            ->withExists([
                'tags as matched_tag' => fn (Builder $t) => $term->matchAny($t, ['tags.name']),
                'tasks as matched_task' => fn (Builder $t) => $term->matchAny($t, ['tasks.title', 'tasks.description']),
                'comments as matched_comment' => fn (Builder $c) => $term->matchAny($c, ['comments.body']),
            ])
            ->with(['step:id,tracker_id,name', 'tracker:id,name'])
            // Recently touched first. Relevance ranking would need a scoring expression
            // over five columns to beat "the thing I was working on", and it would not.
            ->orderByDesc('last_activity_at')
            // One more than we show, purely so the footer can say the list was cut without
            // paying for a COUNT over the same disjunction.
            ->limit(self::LIMIT + 1)
            ->get();

        return [
            'rows' => $matched->take(self::LIMIT)
                ->map(fn (Project $project) => [
                    'project' => $project,
                    'hint' => $this->hintFor($project, $term),
                ])
                ->values(),

            // The extra row we asked for came back, so there is at least one more.
            'truncated' => $matched->count() > self::LIMIT,
        ];
    }

    /**
     * Where the match was, or null when the title already shows it.
     *
     * Order matters: it reports the most visible place the term appears, so a card whose
     * title contains the term gets no hint at all rather than "in a comment" — which
     * would read as though the title match had not been noticed.
     *
     * Private, and it has to be. Every public method on a Livewire component is an
     * endpoint the browser can call; a helper that only ever formats a string has no
     * business being one.
     */
    private function hintFor(Project $project, SearchTerm $term): ?string
    {
        return match (true) {
            $term->matches($project->name) => null,
            $term->matches($project->description) => 'matched in the description',
            (bool) $project->matched_tag => 'matched in a label',
            (bool) $project->matched_task => 'matched in a checklist item',
            (bool) $project->matched_comment => 'matched in a comment',
            // Reachable, and deliberately not an exception: LIKE folds accents under this
            // schema's collation and mb_stripos does not, so a title matching only in that
            // wider sense lands here. A result with no hint is exactly right for it.
            default => null,
        };
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
