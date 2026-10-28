<?php

namespace App\Support\Docuperfect;

/**
 * Counts how many times the agency letterhead block (components/company-header) has been included inside ONE document
 * render, so header_display = 'first_page' prints it once per document.
 *
 * This used to be a `static` variable in the component's compiled view file, which is shared by every document rendered in
 * the same PHP process: the wizard renders every template of a pack in one request, so templates 2..n lost their letterhead
 * (and in a queue worker, every document after the first). Each render gets its own key
 * (Template::headerDisplayViewData() hands one over as `header_render_key`), so documents never share a count.
 */
final class LetterheadPrintCounter
{
    /** @var array<string,int> */
    private static array $counts = [];

    /** Records one include for this render key and returns how many there have been (1 = the first). */
    public static function hit(string $renderKey): int
    {
        return self::$counts[$renderKey] = (self::$counts[$renderKey] ?? 0) + 1;
    }
}
