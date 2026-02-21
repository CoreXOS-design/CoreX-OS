<?php

namespace App\Services\SaleProbability;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;

/**
 * Deterministic confidence + data-quality layer for sale probability outputs.
 *
 * Produces a 0–100 confidence score, A–D grade, quality flags, and a volatility
 * indicator based on dataset size, data-source diversity, elasticity stability,
 * and DOM distribution spread.
 *
 * Contains NO probability math — it only assesses the reliability of inputs.
 */
class ConfidenceScoringService
{
    /** Maximum raw points available (used to normalise to 0–100). */
    private const MAX_RAW = 80;

    public function evaluate(
        MarketAnalyticsResult $maResult,
        SaleProbabilityResult $spResult,
    ): array {
        $raw   = 0;
        $flags = [];

        // ── 1. Comps (sold comparables) count ────────────────────────────────
        $comps = $maResult->soldCount ?? 0;
        if ($comps >= 12) {
            $raw += 30;
        } elseif ($comps >= 6) {
            $raw += 20;
        } elseif ($comps >= 1) {
            $raw += 5;
            $flags[] = 'low_comps_count';
        } else {
            $flags[] = 'no_comps';
        }

        // ── 2. Elasticity stability (R²) ─────────────────────────────────────
        $rSquared = $maResult->elasticityRSquared;
        if ($rSquared !== null && $rSquared >= 0.5) {
            $raw += 20;
        } else {
            $flags[] = 'unstable_elasticity';
        }

        // ── 3. Volatility indicator (DOM p75 – p25 spread) ───────────────────
        $domCurve  = $maResult->domCurve;
        $domP25    = is_array($domCurve) ? ($domCurve['p25'] ?? null) : null;
        $domP75    = is_array($domCurve) ? ($domCurve['p75'] ?? null) : null;
        $domSpread = ($domP25 !== null && $domP75 !== null) ? ($domP75 - $domP25) : null;

        if ($domSpread === null) {
            $volatility = 'high';
            $flags[]    = 'missing_dom_data';
        } elseif ($domSpread <= 30) {
            $volatility = 'low';
            $raw       += 20;
        } elseif ($domSpread <= 60) {
            $volatility = 'medium';
        } else {
            $volatility = 'high';
            $flags[]    = 'high_dom_volatility';
        }

        // ── 4. Data-source diversity ──────────────────────────────────────────
        $hasSoldData   = ($maResult->soldCount ?? 0) > 0;
        $hasActiveData = ($maResult->activeListingCount ?? 0) > 0;
        if ($hasSoldData && $hasActiveData) {
            $raw += 10;
        } else {
            $flags[] = 'limited_data_sources';
        }

        // ── 5. Insufficient-signal flag (does not deduct points) ─────────────
        if ($spResult->skipReason !== null) {
            $flags[] = 'insufficient_signals';
        }

        // ── 6. Normalise to 0–100 and assign grade ───────────────────────────
        $score = (int) round(min(self::MAX_RAW, $raw) * 100 / self::MAX_RAW);

        if ($score >= 80) {
            $grade = 'A';
        } elseif ($score >= 60) {
            $grade = 'B';
        } elseif ($score >= 40) {
            $grade = 'C';
        } else {
            $grade = 'D';
        }

        return [
            'confidence_score'     => $score,
            'confidence_grade'     => $grade,
            'data_quality_flags'   => $flags,
            'volatility_indicator' => $volatility,
        ];
    }
}
