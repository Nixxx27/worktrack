<?php

namespace App\Services\Projects;

use App\Models\Tag;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolve free-typed tag names to rows within ONE tracker (FR-4.2).
 *
 * Free-typed tags rot in a predictable way: "urgent", "Urgent" and "  urgent " become
 * three rows, the filter list fills with near-duplicates, and filtering by one of them
 * quietly under-reports. So normalisation happens here, at the single write seam,
 * rather than being left to whichever form happens to be submitting.
 */
class TagService
{
    /**
     * The colours a label may have, keyed by the name a screen reader reads out.
     *
     * A CLOSED set, not a hex field. Two reasons, and only one of them is taste:
     * the value is written straight into a `style="background-color: …"` on every
     * chip, so an open field is a CSS-injection surface; and every colour here is
     * dark enough to carry the white uppercase chip text, which a free hex is not —
     * one #ffe066 label and the board has a chip nobody can read.
     *
     * Ordered, because the first six are still assigned round-robin so a board is
     * legible before anyone opens the picker. Slate is deliberately absent — it
     * reads as "disabled" next to the card chrome.
     *
     * @var array<string, string>
     */
    public const PALETTE = [
        'Sky' => '#0369a1',
        'Emerald' => '#047857',
        'Amber' => '#b45309',
        'Rose' => '#be123c',
        'Violet' => '#6d28d9',
        'Teal' => '#0f766e',
        'Blue' => '#1d4ed8',
        'Moss' => '#3f6212',
        'Orange' => '#c2410c',
        'Magenta' => '#a21caf',
        'Red' => '#b91c1c',
        'Ocean' => '#155e75',
    ];

    /**
     * Names to Tag rows, creating what does not exist yet.
     *
     * Case-insensitive and whitespace-insensitive: "Urgent" typed today matches
     * "urgent" typed last week, and the row keeps whichever casing was used first, so
     * the label the team already recognises does not silently change under them.
     *
     * @param  list<string>  $names
     * @return Collection<int, Tag>
     */
    public function resolve(Tracker $tracker, array $names, User $actor): Collection
    {
        $wanted = $this->normalise($names);

        if ($wanted === []) {
            return collect();
        }

        return DB::transaction(function () use ($tracker, $wanted, $actor) {
            $existing = Tag::where('tracker_id', $tracker->id)->get()
                ->keyBy(fn (Tag $tag) => $this->key($tag->name));

            $resolved = collect();

            foreach ($wanted as $key => $display) {
                if ($existing->has($key)) {
                    $resolved->push($existing->get($key));

                    continue;
                }

                // firstOrCreate against uk_tags_tracker_name rather than create():
                // two people typing the same new tag in the same second would
                // otherwise race, and one of them would get a duplicate-key error
                // out of what is, to them, just typing a word.
                $tag = Tag::firstOrCreate(
                    ['tracker_id' => $tracker->id, 'name' => $display],
                    [
                        'color' => $this->rotate($existing->count()),
                        'created_by_user_id' => $actor->id,
                    ],
                );

                $existing->put($key, $tag);
                $resolved->push($tag);
            }

            return $resolved;
        });
    }

    /**
     * Create ONE label with a colour someone chose, or hand back the one that exists.
     *
     * Separate from resolve() because the two are different acts. resolve() is
     * "make sure these names exist" and runs on every card save, where a colour is
     * nobody's intent; this is "define this label", which is a deliberate action with
     * a deliberate colour, and it is the only path a chosen colour travels.
     *
     * An existing label keeps its colour. Typing a name that is already on the list
     * is how someone re-uses a label, not how they recolour one — silently repainting
     * every card carrying "Urgent" because a colour swatch happened to be selected is
     * a change nobody asked for. Recolouring is recolour()'s job, and it is explicit.
     */
    public function define(Tracker $tracker, string $name, ?string $color, User $actor): ?Tag
    {
        $wanted = $this->normalise([$name]);

        if ($wanted === []) {
            return null;
        }

        $display = reset($wanted);
        $key = array_key_first($wanted);

        return DB::transaction(function () use ($tracker, $display, $key, $color, $actor) {
            $existing = Tag::where('tracker_id', $tracker->id)->get();

            $match = $existing->first(fn (Tag $tag) => $this->key($tag->name) === $key);

            if ($match) {
                return $match;
            }

            return Tag::firstOrCreate(
                ['tracker_id' => $tracker->id, 'name' => $display],
                [
                    'color' => $this->paints($color) ? $color : $this->rotate($existing->count()),
                    'created_by_user_id' => $actor->id,
                ],
            );
        });
    }

    /**
     * Repaint an existing label.
     *
     * Tracker-wide by construction: one row backs every chip carrying that name, so
     * this changes the colour on every card at once. That is the intent — a label
     * whose colour means one thing here and another there is worse than no colour —
     * but it is why the caller must be someone allowed to change this tracker's board,
     * not merely someone looking at one card.
     */
    public function recolor(Tag $tag, string $color): Tag
    {
        // Guarded here rather than only at the form, because this is the seam every
        // path reaches and the value ends up inside a style attribute.
        if (! $this->paints($color)) {
            throw new InvalidArgumentException('Colour is not one of the label palette.');
        }

        $tag->forceFill(['color' => $color])->save();

        return $tag;
    }

    /** The colour a new label in this tracker would be given if nobody picked one. */
    public function nextColor(Tracker $tracker): string
    {
        return $this->rotate(Tag::where('tracker_id', $tracker->id)->count());
    }

    /**
     * The palette as a plain list of hex values, for `Rule::in` and for iteration.
     *
     * @return list<string>
     */
    public static function colors(): array
    {
        return array_values(self::PALETTE);
    }

    /**
     * Every tag in a tracker, for the autocomplete.
     *
     * Scoped by TrackerVisibilityScope like every other read here, so passing a
     * tracker the caller cannot see returns nothing rather than leaking its labels.
     *
     * @return Collection<int, Tag>
     */
    public function forTracker(Tracker $tracker): Collection
    {
        return Tag::where('tracker_id', $tracker->id)->orderBy('name')->get();
    }

    /**
     * Trim, drop blanks, cap length, and de-duplicate case-insensitively.
     *
     * @param  list<string>  $names
     * @return array<string, string> normalised key => display name
     */
    private function normalise(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            $display = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

            if ($display === '') {
                continue;
            }

            // Matches tags.name — truncating here rather than letting MySQL do it
            // means the row and the value we looked it up by cannot disagree.
            $display = mb_substr($display, 0, 40);
            $key = $this->key($display);

            // First spelling wins, so the casing already on the board is preserved.
            $out[$key] ??= $display;
        }

        return $out;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** The nth palette colour, wrapping — how a board gets colour with nobody choosing. */
    private function rotate(int $n): string
    {
        $colors = self::colors();

        return $colors[$n % count($colors)];
    }

    private function paints(?string $color): bool
    {
        return $color !== null && in_array($color, self::colors(), true);
    }
}
