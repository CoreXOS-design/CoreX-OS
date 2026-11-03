<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * The addresses MIC opens for a working suburb (.ai/specs/mic-work-tab.md §11.2.2, §11.4). One place builds them so the Tile 11
 * panel, the MIC Settings page and the tests can never drift from what the extension reads.
 *
 * Search links open the suburb's FOR-SALE search sorted NEWEST FIRST:
 *   P24: `?sp=so%3dNewest` (P24's own sort link; the crawl record accepts the `so` key - CrawlRecords::P24_ALLOWED_SP_KEYS)
 *   PP:  `sorttype=Date&sortorder=Descending` (checked 31 Oct 2026 against the live site: newest adverts first; `sortorder=Newest` returns nothing)
 * Area links carry `#corex-area=all&province=<slug>`: the extension reads the trends page, then moves on to the agencies page and the
 * street list by itself, so ONE click per suburb refreshes everything.
 */
final class P24AreaPageLinks
{
    public static function slug(?string $v): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $v)), '-');
    }

    public static function p24SearchUrl(string $suburbSlug, string $citySlug, string $provinceSlug, int $p24SuburbId): string
    {
        return "https://www.property24.com/for-sale/{$suburbSlug}/{$citySlug}/{$provinceSlug}/{$p24SuburbId}?sp=so%3dNewest";
    }

    public static function ppSearchUrl(int $ppSuburbId): string
    {
        return "https://www.privateproperty.co.za/for-sale/advanced-search?si={$ppSuburbId}&sorttype=Date&sortorder=Descending";
    }

    /** @return array{trends: string, agencies: string, street_index: string} */
    public static function areaUrls(string $suburbSlug, string $citySlug, string $provinceSlug, int $p24SuburbId): array
    {
        $hash = '#corex-area=all&province=' . $provinceSlug;

        return [
            'trends'       => "https://www.property24.com/{$citySlug}/{$suburbSlug}/property-trends/{$p24SuburbId}{$hash}",
            'agencies'     => "https://www.property24.com/estate-agencies/{$suburbSlug}/{$citySlug}/{$provinceSlug}/{$p24SuburbId}{$hash}",
            'street_index' => "https://www.property24.com/property-values/{$suburbSlug}/{$citySlug}/{$provinceSlug}/{$p24SuburbId}{$hash}",
        ];
    }
}
