<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * A comment as it is read: links clickable, and the mentions that actually landed
 * visibly different from the ones that did not.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHY THE HIGHLIGHT IS DRIVEN BY THE SNAPSHOT AND NOT BY THE TEXT.
 *
 * Painting everything that LOOKS like a mention would be the easy version and it
 * would lie in the direction that costs the most. A mention that matched nobody is
 * exactly the case the author needs to see, because it is the one where the person
 * they were talking to is never told. If "@jonathon" — a typo — came out looking
 * identical to "@jonathan", the feature would keep failing silently, which is what
 * it did for as long as nothing on this screen distinguished the two.
 *
 * So the members handed to Mentions here are the ones stored in comment_mentions at
 * write time, not the current tracker roster. The snapshot decides WHO counts and the
 * matcher decides WHICH CHARACTERS to paint. A mention only gets a highlight if an
 * email went with it.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Escaping follows Linkify's rule, because this class writes the only other markup in
 * the application built from user input: the body is SPLIT on the mention spans, each
 * gap is handed to Linkify (which escapes its own pieces), and the mention text and
 * the resolved name are escaped individually before the span is assembled. Nothing an
 * author typed can become a tag, and nothing is re-parsed after it is escaped. The
 * return is an HtmlString so views still echo it through the ordinary `{{ }}`.
 */
class CommentText
{
    /**
     * @param  Collection<int, User>  $mentioned  who this comment actually notified
     */
    public static function render(?string $body, Collection $mentioned): HtmlString
    {
        $body = (string) $body;

        $spans = Mentions::parse($body, $mentioned)->sortBy('start')->values();

        if ($spans->isEmpty()) {
            return Linkify::text($body);
        }

        $html = '';
        $cursor = 0;

        foreach ($spans as $span) {
            $html .= Linkify::text(substr($body, $cursor, $span['start'] - $cursor))->toHtml();
            $html .= self::chip(substr($body, $span['start'], $span['length']), $span['user']);

            $cursor = $span['start'] + $span['length'];
        }

        return new HtmlString($html.Linkify::text(substr($body, $cursor))->toHtml());
    }

    /**
     * The visible text stays whatever the author typed — "@jonathan" is not rewritten
     * into "@Jonathan Cruz", because rewriting somebody's words to make our matching
     * look tidier is not ours to do. The full name goes on the title instead, and only
     * when it differs from what was typed: a tooltip that repeats the word underneath
     * it is noise on every mention the picker inserted.
     */
    private static function chip(string $text, User $user): string
    {
        $typed = trim(mb_substr($text, 1));

        return sprintf(
            '<span class="mention"%s>%s</span>',
            $typed === $user->name ? '' : sprintf(' title="%s"', e($user->name)),
            e($text),
        );
    }
}
