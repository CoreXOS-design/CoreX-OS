<?php

namespace App\Services\Minion;

// AT-284 — builds a PUBLIC Property24 for-sale search URL for a suburb.
// No account, no login — the same public results page an agent browses.
class P24SearchUrlBuilder
{
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string) $s, '-');
    }

    /**
     * Canonical P24 for-sale URL: /for-sale/{suburb}/{city}/{province}/{suburbP24Id}
     * The trailing p24 suburb id is authoritative; P24 canonicalises the slugs on load,
     * and the runner records the final_url after navigation.
     */
    public static function forSale(
        string $base,
        string $provinceName,
        string $cityName,
        string $suburbSlugOrName,
        int $suburbP24Id
    ): string {
        return rtrim($base, '/') . '/for-sale/'
            . self::slug($suburbSlugOrName) . '/'
            . self::slug($cityName) . '/'
            . self::slug($provinceName) . '/'
            . $suburbP24Id;
    }
}
