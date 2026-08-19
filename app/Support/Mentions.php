<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Which people a piece of free text names with an @ (FR-4.5, FR-7.4).
 *
 * Pure matching, no database: the caller decides who is mentionable — CommentService
 * asks the tracker member list, the renderer asks the snapshot of who was actually
 * mentioned — and this class only decides which characters name which of them. That
 * split is what lets a comment be PAINTED with exactly the spans that were SENT,
 * rather than with everything that looks like a mention.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHY THE READING SHRINKS INSTEAD OF THE NAME GROWING.
 *
 * The first version of this matched a fixed run of up to four words after the @ and
 * then required a member's WHOLE name to be at the front of it. That reads sensibly
 * and is unusable: nobody types a colleague's full legal name into a comment box, so
 * "@jonathan" resolved to nobody while "@Jonathan Cruz" resolved fine, and the whole
 * feature failed silently for every real message anyone sent.
 *
 * So the run is captured generously and then read SHORTER and shorter — six words,
 * five, four, down to one — and the first length that names somebody wins. Longest
 * first is what keeps "@Rico Santos please look" pointing at Rico Santos rather than
 * at Rico, and stopping at the first length that matches ANYONE is what stops it
 * falling back to Rico afterwards: the author typed more than "Rico" on purpose, and
 * a shorter reading is a guess against the evidence.
 *
 * A reading names somebody if it is their whole name or any whole run of words out of
 * it, and only if it names exactly one person. Those two rules are a pair: the first
 * is what makes the feature usable, the second is what keeps it from mailing the wrong
 * colleague, and neither is safe to ship without the other.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class Mentions
{
    /**
     * The @ must not follow a letter or digit, which is the whole of what keeps
     * "nikko@example.com" out of this. Without it a signature block at the bottom of a
     * comment offers "example.com" as a candidate name, and the day somebody registers
     * a contractor account under a domain-shaped display name it starts mailing them.
     *
     * Six words is a generous ceiling rather than a limit anyone should feel: Filipino
     * names routinely run to five tokens ("Ma. Krystal Gail Mission Reyes"), and the
     * previous cap of four made those members permanently unmentionable — the captured
     * run was strictly shorter than the name, so no reading could ever equal it.
     * Overshooting costs nothing here, because the reading shrinks anyway.
     *
     * `[^\S\n]` rather than `\s`: a name does not straddle a line break, and letting it
     * would swallow the next paragraph into the candidate.
     */
    private const PATTERN = '/(?<![\p{L}\p{N}])@([\p{L}][\p{L}\p{N}\'\-.]*(?:[^\S\n]+[\p{L}][\p{L}\p{N}\'\-.]*){0,5})/u';

    /**
     * Every @ in the text that names one of $members, and where it sits.
     *
     * Offsets are BYTE offsets, as preg_match_all reports them, so callers must slice
     * with substr() and not mb_substr(). The regex only ever ends a match on a whole
     * character, so the spans never cut a multi-byte name in half.
     *
     * @param  Collection<int, User>  $members  who is mentionable, from the caller
     * @return Collection<int, array{start: int, length: int, user: User}>
     */
    public static function parse(?string $body, Collection $members): Collection
    {
        $body = (string) $body;

        if ($body === '' || $members->isEmpty()) {
            return collect();
        }

        if (! preg_match_all(self::PATTERN, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return collect();
        }

        // Folded once for the whole comment, not once per candidate: the member list is
        // walked again for every @ and every shortened reading of it, and Str::ascii()
        // is by far the expensive half of folding.
        //
        // Sorted by id so that the one place this has to break a tie — two members with
        // the same display name — breaks it the same way on every request.
        $folded = $members
            ->sortBy('id')
            ->map(fn (User $user) => ['user' => $user, 'name' => self::fold($user->name)])
            ->reject(fn (array $member) => $member['name'] === '')
            ->values();

        $found = collect();

        foreach ($matches as $match) {
            [, $at] = $match[0];
            [$run, $runAt] = $match[1];

            preg_match_all('/\S+/u', $run, $words, PREG_OFFSET_CAPTURE);

            for ($take = count($words[0]); $take >= 1; $take--) {
                [$word, $wordAt] = $words[0][$take - 1];
                $end = $wordAt + strlen($word);

                [$named, $user] = self::identify(self::fold(substr($run, 0, $end)), $folded);

                if (! $named) {
                    continue;
                }

                if ($user) {
                    $found->push([
                        'start' => $at,
                        'length' => ($runAt - $at) + $end,
                        'user' => $user,
                    ]);
                }

                // Named somebody, or named several people and so nobody. Either way this
                // @ has been decided and a shorter reading of it must not get a turn.
                break;
            }
        }

        return $found;
    }

    /**
     * The distinct people this text names, in the order they are first named.
     *
     * @param  Collection<int, User>  $members
     * @return Collection<int, User>
     */
    public static function resolve(?string $body, Collection $members): Collection
    {
        return self::parse($body, $members)->pluck('user')->unique('id')->values();
    }

    /**
     * Who, if anyone, one reading of a candidate names.
     *
     * @param  Collection<int, array{user: User, name: string}>  $folded
     * @return array{0: bool, 1: ?User} whether this reading names anybody at all, and who
     */
    private static function identify(string $needle, Collection $folded): array
    {
        if ($needle === '') {
            return [false, null];
        }

        // Typed exactly as the app displays it — which is what the picker inserts, so
        // this is the common path and not the fallback it looks like.
        //
        // Duplicate display names resolve to the lowest id rather than to nobody. The
        // product has no usernames, so two colleagues genuinely called Juan Cruz have no
        // spelling that separates them; refusing the mention would leave both of them
        // permanently unreachable with no way for anyone to fix it, which is worse than
        // reaching one of them.
        $exact = $folded->firstWhere('name', $needle);

        if ($exact) {
            return [true, $exact['user']];
        }

        // What was typed is a WHOLE RUN OF WORDS out of somebody's name, from anywhere in
        // it. This is the case the old matcher had backwards, and the reason "@jonathan"
        // now finds Jonathan Cruz.
        //
        // Anywhere, not just the front, because of how names in this organisation are
        // registered: "Ma. Krystal Gail Mission Reyes" is called Krystal by everyone she
        // works with, and a rule anchored to the first word makes the name she actually
        // answers to the one spelling that does not work. Padding both sides with a
        // space is what keeps it to whole words — "@jon" does not name Jonathan, because
        // half a name is a guess and this one sends email.
        $partial = $folded->filter(fn (array $member) => str_contains(" {$member['name']} ", " {$needle} "));

        if ($partial->isEmpty()) {
            return [false, null];
        }

        $people = $partial->pluck('user')->unique('id');

        // "@cruz" with two colleagues called Cruz on the tracker names NEITHER, and this
        // guard is what pays for the looser rule above. Mailing the wrong colleague is
        // worse than mailing none, and the miss is not silent: an unresolved mention
        // renders as plain text, so the author can see it did not land and say which one
        // they meant.
        return [true, $people->count() === 1 ? $people->first() : null];
    }

    /**
     * One spelling for comparison: case folded, accents folded, whitespace collapsed.
     *
     * Accent folding is not politeness, it is the difference between José Rizal being
     * mentionable and not: the keyboard most people type a comment on does not have é
     * on it, and the name as stored does. Str::ascii() is applied to BOTH sides, so a
     * name it cannot transliterate at all — a purely non-Latin script — falls back to
     * the plain lowercase form on both sides and still matches itself.
     */
    private static function fold(string $value): string
    {
        $tidy = fn (string $text) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));

        $ascii = $tidy(Str::ascii($value));

        return $ascii !== '' ? $ascii : $tidy($value);
    }
}
