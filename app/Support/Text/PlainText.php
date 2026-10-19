<?php

declare(strict_types=1);

namespace App\Support\Text;

/**
 * Turns untrusted text (a news feed, an AI answer) into plain text that is safe to store and
 * hand on as-is: no HTML tag survives, however it was written or encoded.
 *
 * The order matters. Stripping tags BEFORE decoding entities lets an encoded tag through:
 * "&lt;script&gt;" has no tag to strip, then decoding turns it into a literal "<script>". So this
 * decodes first, strips second, and repeats until the text stops changing (nested and
 * double-encoded payloads like "&amp;lt;b&amp;gt;" peel one layer per pass). If it has not settled
 * after a handful of passes the text is hostile or garbage: every remaining angle bracket goes.
 */
final class PlainText
{
    private const MAX_PASSES = 6;

    public static function strip(string $text): string
    {
        for ($i = 0; $i < self::MAX_PASSES; $i++) {
            $before = $text;

            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Script/style blocks go with their contents — strip_tags alone would leave the code as text.
            $text = preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $text) ?? $text;
            $text = strip_tags($text);

            if ($text === $before) {
                break;
            }
        }

        // Settled (or gave up): nothing that could open a tag, comment or processing instruction may remain.
        $text = preg_replace('/<(?=[a-z\/!?%])/i', '', $text) ?? $text;
        if ($i === self::MAX_PASSES) {
            $text = str_replace(['<', '>'], '', $text);
        }

        // Control characters (keep newline and tab) never belong in a title or article.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    }
}
