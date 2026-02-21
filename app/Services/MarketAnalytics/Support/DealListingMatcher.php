<?php

namespace App\Services\MarketAnalytics\Support;

use Illuminate\Support\Facades\DB;

/**
 * DealListingMatcher — deterministic Deal↔ListingStock match for Tier 2 DOM resolution (step 2.8).
 *
 * For each comp row that has no listed_date (cannot use Tier 1 DOM), this class
 * attempts to find the best matching ListingStock record and computes proxy DOM as
 * sold_date − listing.listed_at.
 *
 * Matching rules (v1):
 *   Candidates: listing_stocks where
 *     - branch_id = supplied branch_id
 *     - listed_at IS NOT NULL
 *     - listed_at ≤ sold_date
 *     - listed_at ≥ sold_date − 365 days
 *   Score = Jaccard token-set overlap on normalised address strings (0–100)
 *           + 10 if |deal_price − listing_price| / listing_price ≤ 20%
 *           + streetNumberBonus: +15 same leading number, −20 different number
 *           + externalIdBonus: +25 if listing external_id/ref found in deal file_no/remarks
 *   Accept if best score ≥ SCORE_THRESHOLD (80).
 *
 * DB access is batched (two queries total per call regardless of comp count).
 *
 * @see DomCurveMetric   (consumes simplified row_hash → dom_days projection)
 * @see ElasticityProxyMetric (same)
 */
class DealListingMatcher
{
    public const MATCH_VERSION        = 'deal_listing_match_v1';
    public const SCORE_THRESHOLD      = 80;
    public const MAX_LISTING_AGE_DAYS = 365;
    public const PRICE_TOLERANCE_PCT  = 0.20;

    public const STREET_NUMBER_MATCH_BONUS     = 15;
    public const STREET_NUMBER_MISMATCH_PENALTY = -20;
    public const EXTERNAL_ID_MATCH_BONUS       = 25;

    /**
     * Build a row_hash → DOM resolution entry map for comp rows that have no listed_date.
     *
     * Comp rows that already have a listed_date are skipped here (handled by Tier 1
     * in DomCurveMetric / ElasticityProxyMetric).
     *
     * @param  array  $compRows   ComparableSet rows (each element has: deal_id, sold_date,
     *                            row_hash, sold_price_inc, listed_date)
     * @param  int    $branchId
     * @param  string $periodFrom Start of sold period (Y-m-d) — used to widen the listing fetch window
     * @param  string $periodTo   End of sold period / reference date (Y-m-d)
     * @return array<string, array{dom_days: int, tier: string, listing_stock_id: int, score: int, listed_at: string}>
     *         Keyed by row_hash.  Rows with no accepted match are omitted.
     */
    public function buildDomResolutionMap(
        array  $compRows,
        int    $branchId,
        string $periodFrom,
        string $periodTo,
    ): array {
        if (empty($compRows)) {
            return [];
        }

        // 1. Collect only rows that need Tier 2 (no listed_date present)
        $needsMatch = [];
        foreach ($compRows as $row) {
            if (($row['listed_date'] ?? null) !== null) {
                continue;  // Tier 1 will handle this row
            }
            $dealId = $row['deal_id'] ?? null;
            if ($dealId === null) {
                continue;
            }
            $needsMatch[$dealId] = $row;
        }

        if (empty($needsMatch)) {
            return [];
        }

        // 2. Fetch deal data in a single query (address + price + text for external-id matching)
        $dealRows = DB::table('deals')
            ->whereIn('id', array_keys($needsMatch))
            ->select(['id', 'property_address', 'property_value', 'file_no', 'remarks'])
            ->get()
            ->keyBy('id');

        // 3. Fetch listing candidates in a single query.
        //    Rough date window: earliest possible listed_at = periodFrom − 365 days.
        //    Per-row filtering (against each comp's sold_date) happens in step 4.
        $listingWindowFrom = date('Y-m-d', strtotime($periodFrom . ' -' . self::MAX_LISTING_AGE_DAYS . ' days'));

        $candidates = DB::table('listing_stocks')
            ->where('branch_id', $branchId)
            ->whereNotNull('listed_at')
            ->where('listed_at', '<=', $periodTo)
            ->where('listed_at', '>=', $listingWindowFrom)
            ->select(['id', 'property', 'price_cents', 'listed_at', 'external_id', 'external_ref'])
            ->get()
            ->all();

        if (empty($candidates)) {
            return [];
        }

        // 4. Match each comp row to the best candidate
        $map = [];

        foreach ($needsMatch as $dealId => $row) {
            $deal = $dealRows->get($dealId) ?? null;
            if ($deal === null) {
                continue;
            }

            $soldDate   = $row['sold_date'];
            $rowHash    = $row['row_hash'];
            $dealPrice  = (float)($deal->property_value ?? 0);
            $windowFrom = date('Y-m-d', strtotime($soldDate . ' -' . self::MAX_LISTING_AGE_DAYS . ' days'));

            $bestScore   = -1;
            $bestListing = null;

            $dealAddrTokens = $this->normalizeAddress((string)($deal->property_address ?? ''));

            foreach ($candidates as $listing) {
                // listed_at stored as datetime string; take date portion only
                $listedAt = substr((string)$listing->listed_at, 0, 10);

                // Per-row date guard: listed_at must be ≤ sold_date and ≥ sold_date − 365 days
                if ($listedAt > $soldDate || $listedAt < $windowFrom) {
                    continue;
                }

                $listingAddrTokens = $this->normalizeAddress((string)($listing->property ?? ''));

                $s = $this->score(
                    dealAddress:       (string)($deal->property_address ?? ''),
                    listingAddress:    (string)($listing->property ?? ''),
                    dealPrice:         $dealPrice,
                    listingPriceCents: $listing->price_cents,
                )
                + $this->streetNumberBonus($dealAddrTokens, $listingAddrTokens)
                + $this->externalIdBonus(
                    dealFileNo:         $deal->file_no ?? null,
                    dealRemarks:        $deal->remarks ?? null,
                    listingExternalId:  $listing->external_id ?? null,
                    listingExternalRef: $listing->external_ref ?? null,
                );

                if ($s > $bestScore) {
                    $bestScore   = $s;
                    $bestListing = $listing;
                }
            }

            if ($bestScore < self::SCORE_THRESHOLD || $bestListing === null) {
                continue;
            }

            $listedAt = substr((string)$bestListing->listed_at, 0, 10);
            $dom      = $this->computeDomDays($soldDate, $listedAt);

            if ($dom === null) {
                continue;
            }

            $map[$rowHash] = [
                'dom_days'         => $dom,
                'tier'             => 'import_proxy',
                'listing_stock_id' => (int)$bestListing->id,
                'score'            => $bestScore,
                'listed_at'        => $listedAt,
            ];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Pure scoring helpers (public so they are directly unit-testable)
    // -------------------------------------------------------------------------

    /**
     * Compute match score for a deal↔listing pair.
     *
     * Base = Jaccard token-set overlap on normalised addresses (0–100).
     * Bonus = +10 when both prices are present and |deal − listing| / listing ≤ 20 %.
     * Maximum possible score = 110.
     */
    public function score(
        string $dealAddress,
        string $listingAddress,
        float  $dealPrice        = 0.0,
        ?int   $listingPriceCents = null,
    ): int {
        $addrScore = $this->tokenSetOverlap(
            $this->normalizeAddress($dealAddress),
            $this->normalizeAddress($listingAddress),
        );

        $bonus = 0;

        if (
            $dealPrice > 0.0
            && $listingPriceCents !== null
            && $listingPriceCents > 0
        ) {
            $listingPrice = $listingPriceCents / 100.0;
            if (abs($dealPrice - $listingPrice) / $listingPrice <= self::PRICE_TOLERANCE_PCT) {
                $bonus = 10;
            }
        }

        return $addrScore + $bonus;
    }

    /**
     * Normalise an address string into a sorted, unique array of lowercase word tokens.
     * Strips all characters that are not alphanumeric or whitespace.
     *
     * @return string[]
     */
    public function normalizeAddress(string $address): array
    {
        $lower   = mb_strtolower($address);
        $cleaned = preg_replace('/[^a-z0-9\s]/u', ' ', $lower) ?? '';
        $tokens  = preg_split('/\s+/u', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $unique  = array_unique($tokens);
        sort($unique);

        return array_values($unique);
    }

    /**
     * Jaccard token-set overlap scaled to 0–100 (integer, rounded).
     * Returns 0 when either set is empty.
     *
     * @param  string[] $a
     * @param  string[] $b
     */
    public function tokenSetOverlap(array $a, array $b): int
    {
        if (empty($a) || empty($b)) {
            return 0;
        }

        $intersection = array_intersect($a, $b);
        $union        = array_unique(array_merge($a, $b));

        return (int)round(count($intersection) / count($union) * 100);
    }

    /**
     * Street-number bonus/penalty applied on top of score().
     *
     * +STREET_NUMBER_MATCH_BONUS     when both token arrays share the same leading
     *                                 purely-numeric token (same house number → strong signal).
     * +STREET_NUMBER_MISMATCH_PENALTY when both have a numeric leader but they differ
     *                                 (different house number → almost certainly wrong match).
     * 0                               when either set has no leading numeric token
     *                                 (cannot determine — no adjustment).
     *
     * @param  string[] $tokensA  Normalised tokens for address A
     * @param  string[] $tokensB  Normalised tokens for address B
     */
    public function streetNumberBonus(array $tokensA, array $tokensB): int
    {
        $numA = $this->leadingNumericToken($tokensA);
        $numB = $this->leadingNumericToken($tokensB);

        if ($numA === null || $numB === null) {
            return 0;
        }

        return $numA === $numB
            ? self::STREET_NUMBER_MATCH_BONUS
            : self::STREET_NUMBER_MISMATCH_PENALTY;
    }

    /**
     * External-ID bonus: +EXTERNAL_ID_MATCH_BONUS when the listing's external_id or
     * external_ref appears verbatim (case-insensitive) in the deal's file_no or remarks.
     *
     * This covers the common pattern where the Propcon/MDA listing reference is written
     * into the deal's file number or remarks field.
     */
    public function externalIdBonus(
        ?string $dealFileNo,
        ?string $dealRemarks,
        ?string $listingExternalId,
        ?string $listingExternalRef,
    ): int {
        if ($listingExternalId === null && $listingExternalRef === null) {
            return 0;
        }

        $dealText = mb_strtolower(($dealFileNo ?? '') . ' ' . ($dealRemarks ?? ''));

        foreach ([$listingExternalId, $listingExternalRef] as $id) {
            if ($id !== null && $id !== '' && str_contains($dealText, mb_strtolower($id))) {
                return self::EXTERNAL_ID_MATCH_BONUS;
            }
        }

        return 0;
    }

    /**
     * Returns the first purely-numeric token from a normalised address token array,
     * or null if none is found.
     * Used to identify the street/erf number component of an address.
     *
     * @param  string[] $tokens
     */
    private function leadingNumericToken(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Compute DOM in whole days (sold_date − listed_date, UTC).
     * Returns null when listed_date > sold_date (anomalous) or on parse error.
     */
    public function computeDomDays(string $soldDate, string $listedDate): ?int
    {
        try {
            $utc    = new \DateTimeZone('UTC');
            $listed = new \DateTimeImmutable($listedDate, $utc);
            $sold   = new \DateTimeImmutable($soldDate, $utc);
        } catch (\Exception) {
            return null;
        }

        $diff = $listed->diff($sold);

        return $diff->invert === 1 ? null : $diff->days;
    }
}
