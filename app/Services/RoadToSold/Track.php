<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use Illuminate\Http\Request;

/**
 * AT-454 - which track (sales now, rentals later) a Road to Sold request is about. Taken from the route
 * default or the `track` query / form field; anything unknown is absorbed to sales.
 */
final class Track
{
    public const ALL = [RtsSetting::TRACK_SALES, RtsSetting::TRACK_RENTALS];

    public static function fromRequest(Request $request): string
    {
        $raw = $request->route('track') ?? $request->input('track');

        return is_string($raw) && in_array($raw, self::ALL, true) ? $raw : RtsSetting::TRACK_SALES;
    }

    /** Query parameters that carry the track in a link: nothing for sales (the default), so sales URLs stay as they are. */
    public static function params(string $track): array
    {
        return $track === RtsSetting::TRACK_SALES ? [] : ['track' => $track];
    }
}
