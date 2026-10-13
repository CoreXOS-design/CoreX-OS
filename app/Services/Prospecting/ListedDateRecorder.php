<?php

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AT-454 — where a MIC listing's REAL listed date comes from.
 *
 * The portals' search tiles carry no listed date (Johan, 13 Oct), so the capture of search results never
 * has one. A date exists only when a single listing PAGE is read. Two existing listing-page captures
 * already send it, with the portal reference, in their payload:
 *   - Other Agency Stock import   (listing_ref + date_posted)
 *   - Pull property               (portal_ref  + date_posted)
 * Both call record() so the MIC listing for that advert learns its real date. Nothing is scraped anew.
 * (P24 alert emails will call it later; an agent's manual correction is separate and always wins.)
 *
 * It only UPDATES an advert CoreX already holds in MIC — it never creates a listing. An agent-corrected
 * date (listed_date_source = 'agent') is never overwritten. Never throws: a date problem must not fail the
 * import that called it.
 */
final class ListedDateRecorder
{
    /** @return int how many MIC listings were updated */
    public function record(int $agencyId, string $portal, ?string $portalRef, mixed $rawDate): int
    {
        try {
            $date = self::parseDate($rawDate);
            $ref = self::portalRef($portal, $portalRef);
            if ($agencyId <= 0 || $date === null || $ref === null) {
                return 0;
            }

            return ProspectingListing::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('portal_source', strtolower($portal))
                ->where('portal_ref', $ref)
                ->where(fn ($q) => $q->whereNull('listed_date_source')
                    ->orWhere('listed_date_source', '!=', ProspectingListing::LISTED_DATE_SOURCE_AGENT))
                ->where(fn ($q) => $q->whereNull('listed_date')->orWhere('listed_date', '!=', $date))
                ->update([
                    'listed_date'        => $date,
                    'listed_date_source' => ProspectingListing::LISTED_DATE_SOURCE_PORTAL,
                    'updated_at'         => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('ListedDateRecorder: could not store a listed date (ignored)', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    /** The MIC form of a portal reference: 'P24-<digits>' / 'PP-<ref>'. Accepts the bare or prefixed form. */
    public static function portalRef(string $portal, ?string $ref): ?string
    {
        $ref = trim((string) $ref);
        $portal = strtolower($portal);
        if ($ref === '') {
            return null;
        }
        if ($portal === 'p24') {
            $digits = preg_replace('/\D+/', '', preg_replace('/^P24-?/i', '', $ref));

            return $digits !== '' ? 'P24-' . $digits : null;
        }
        if ($portal === 'pp') {
            $bare = preg_replace('/^PP-?/i', '', $ref);

            return $bare !== '' ? 'PP-' . $bare : null;
        }

        return null;
    }

    /**
     * Whatever date text a page gave ("17 July 2026", "2026-07-17") as Y-m-d, or null. Absorbs blanks,
     * words, impossible dates ("31 Feb"), future dates and anything before 2000.
     */
    public static function parseDate(mixed $raw): ?string
    {
        $text = trim((string) $raw);
        if ($text === '' || $text === '0' || $text === '-') {
            return null;
        }
        // Carbon silently rolls an impossible date over ("31 Feb" -> 3 Mar); date_parse() flags it.
        $parsed = date_parse($text);
        if (($parsed['error_count'] ?? 0) > 0 || ($parsed['warning_count'] ?? 0) > 0) {
            return null;
        }
        try {
            $date = Carbon::parse($text)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
        if ($date->gt(now()->endOfDay()) || $date->lt(Carbon::create(2000, 1, 1))) {
            return null;
        }

        return $date->toDateString();
    }
}
