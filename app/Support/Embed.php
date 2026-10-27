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

        return $request->boolean('embed') || $request->header('Sec-Fetch-Dest') === 'iframe';
    }
}
