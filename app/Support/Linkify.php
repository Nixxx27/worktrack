<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Free text a person typed, rendered with the URLs in it made clickable.
 *
 * A pasted link is the most common thing in a comment after prose — a spec in Drive, a
 * ticket, a vendor's quote — and until now it arrived as inert text the reader had to
 * select, copy and paste into the address bar. Every one of those steps is a place the
 * selection clips a character and the link silently 404s.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHY THIS ESCAPES FIRST AND LINKIFIES SECOND, RATHER THAN THE OTHER WAY ROUND.
 *
 * This is the only place in the application that emits HTML built from user input, so
 * the ordering is the whole safety argument. The text is SPLIT on the URL pattern and
 * each piece is escaped individually — prose with e(), the href and the label with
 * e() — and the anchor tags are the only markup this class writes. Nothing the author
 * typed can become a tag, because the pieces are escaped before they are concatenated
 * and never re-parsed afterwards.
 *
 * Escaping the whole string first and then running the pattern over the RESULT is the
 * version that looks equivalent and is not: `?a=1&b=2` becomes `?a=1&amp;b=2`, and the
 * URL matcher then either swallows the entity into the href or stops at the `&`,
 * depending on where its character class happens to end. Correct HTML, wrong link.
 *
 * The return value is an HtmlString, so views echo it through the ordinary `{{ }}` —
 * e() returns Htmlable::toHtml() untouched. No `{!! !!}` is introduced anywhere,
 * which keeps "this codebase never emits unescaped Blade" true as a property someone
 * can grep for rather than a habit they have to remember.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
class Linkify
{
    /**
     * Only http(s) and a bare `www.` host are recognised, which is a deliberate floor
     * rather than an oversight. A scheme-blind matcher would have to decide whether
     * `mailto:`, `data:` and `javascript:` are links, and the last two are how an
     * anchor becomes an execution vector; requiring the scheme means the dangerous
     * ones are simply not matched instead of matched and then filtered.
     *
     * `[^\s<]+` overruns on purpose — trailing punctuation is trimmed off in code
     * below, where the balanced-parenthesis case can be reasoned about, rather than
     * inside a character class nobody can read a year later.
     */
    private const PATTERN = '~\b(https?://[^\s<]+|www\.[^\s<]+)~i';

    /**
     * Characters that end a SENTENCE containing a URL, not the URL itself.
     *
     * "See https://example.com/spec." — the full stop belongs to the prose, and a
     * link that carries it lands on a path that does not exist.
     */
    private const TRAILING = ".,;:!?\"'”’)]}>";

    public static function text(?string $text): HtmlString
    {
        $pieces = preg_split(self::PATTERN, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $html = '';

        // One capture group means the split alternates prose, match, prose, match…
        foreach ($pieces as $index => $piece) {
            $html .= $index % 2 === 0 ? e($piece) : self::anchor($piece);
        }

        return new HtmlString($html);
    }

    private static function anchor(string $match): string
    {
        [$url, $trailing] = self::trimTrailingPunctuation($match);

        // "https://" or "www." with nothing after it is a typo, not a destination.
        if (! preg_match('~^(?:https?://|www\.)[^\s/?#]+~i', $url)) {
            return e($match);
        }

        // A bare host gets the scheme the browser would have assumed, and gets it as
        // https rather than http: this is a link someone pasted in 2026, and the
        // downgrade would be ours rather than theirs.
        $href = str_starts_with(strtolower($url), 'www.') ? 'https://'.$url : $url;

        return sprintf(
            // target=_blank because the drawer is a working surface with an unsaved
            // comment box in it, and navigating away from it discards the draft.
            // noopener/noreferrer are what make that safe; nofollow is because the
            // destination is whatever a colleague pasted.
            '<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>%s',
            e($href),
            e($url),
            e($trailing),
        );
    }

    /**
     * @return array{0: string, 1: string} the URL, and the punctuation that followed it
     */
    private static function trimTrailingPunctuation(string $url): array
    {
        $trailing = '';

        while ($url !== '') {
            $last = mb_substr($url, -1);

            if (! str_contains(self::TRAILING, $last)) {
                break;
            }

            // A closing bracket that has an opener inside the URL is part of it —
            // Confluence and SharePoint both mint paths like /Shared%20(Docs)/ — so
            // only an UNMATCHED one is treated as prose.
            if ($last === ')' && substr_count($url, ')') <= substr_count($url, '(')) {
                break;
            }

            $trailing = $last.$trailing;
            $url = mb_substr($url, 0, -1);
        }

        return [$url, $trailing];
    }
}
