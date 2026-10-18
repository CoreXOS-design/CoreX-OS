<?php

namespace App\Services\RoadToSold\Support;

use App\Models\RoadToSold\RtsSetting;
use Illuminate\Http\Request;

/**
 * AT-454 - which track (sales / rentals) the current Road to Sold settings request belongs to, and the
 * route-name base its links must use. The track comes from where the screens are MOUNTED, not from
 * anything a user posts: the sales routes are named `road-to-sold.settings.*`, and Road to Rented
 * mounts the very same route file under `road-to-rented.settings.*` and gets the same screens for
 * its own track. Unknown or missing = sales.
 */
final class Routing
{
    /** route-name root => track */
    public const TRACK_BY_ROOT = [
        'road-to-sold'   => RtsSetting::TRACK_SALES,
        'road-to-rented' => RtsSetting::TRACK_RENTALS,
    ];

    public static function track(?Request $request = null): string
    {
        $root = explode('.', (string) (($request ?? request())->route()?->getName() ?? ''), 2)[0];

        return self::TRACK_BY_ROOT[$root] ?? RtsSetting::TRACK_SALES;
    }

    /** e.g. "road-to-sold.settings." - prepend to a screen's short route name. */
    public static function base(?Request $request = null): string
    {
        $name = (string) (($request ?? request())->route()?->getName() ?? '');
        $pos  = strpos($name, '.settings.');
        if ($pos === false) {
            return 'road-to-sold.settings.';
        }
        $root = substr($name, 0, $pos);

        return isset(self::TRACK_BY_ROOT[$root]) ? $root . '.settings.' : 'road-to-sold.settings.';
    }
}
