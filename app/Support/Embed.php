<?php

namespace App\Support;

/**
 * Embed mode (road-to-sold.md section 22.9): a CoreX screen shown inside the Road to Sold pop-up renders WITHOUT the sidebar, banners,
 * header and floating widgets. It removes chrome only - it grants no access and changes no permission.
 *
 * On when the address says ?embed=1, or when the browser says the request is for a frame (Sec-Fetch-Dest: iframe) - so a form that saves
 * and redirects inside the pop-up stays chrome-less without every redirect having to carry the parameter.
 */
final class Embed
{
    public static function on(): bool
    {
        $request = request();

        if ($request->boolean('embed') || $request->header('Sec-Fetch-Dest') === 'iframe') {
            return true;
        }

        // A fetch()/XHR made BY a screen that is shown in the pop-up (the deed picker, "Link as seller", the number ticks): the browser calls it "empty", not
        // "iframe", and it carries no ?embed - but the screen that made it was opened with ?embed=1, and says so in its Referer. Without this the deed link
        // behaved as if on the full page and made a second property and file beside the record's own (road-to-sold.md 23.23 drop 7).
        if ($request->header('Sec-Fetch-Dest') === 'empty' && ! $request->isMethodSafe()) {
            return (bool) preg_match('/[?&]embed=1(?:&|$)/', (string) $request->headers->get('referer'));
        }

        return false;
    }
}
