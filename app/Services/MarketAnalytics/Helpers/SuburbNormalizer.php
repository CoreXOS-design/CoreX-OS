<?php

namespace App\Services\MarketAnalytics\Helpers;

class SuburbNormalizer
{
    /**
     * Normalise to lowercase, trimmed, single-space string.
     * e.g. "  North  Shore " → "north shore"
     */
    public static function normalize(string $suburb): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $suburb)));
    }

    /**
     * URL-safe slug: spaces become hyphens.
     * e.g. "north shore" → "north-shore"
     */
    public static function slug(string $suburb): string
    {
        return str_replace(' ', '-', self::normalize($suburb));
    }
}
