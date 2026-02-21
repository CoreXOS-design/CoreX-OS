<?php

namespace App\Services\Presentations;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;

/**
 * Deterministic explainability engine for seller-facing presentations.
 *
 * Translates raw market analytics and probability outputs into plain-English
 * key drivers, risk factors, a competitive positioning summary, and a pricing
 * leverage note.
 *
 * Contains NO probability math — it only interprets already-computed results.
 */
class ExplainabilityService
{
    /**
     * @param  array $competitiveStock  competitive_stock sub-array from MA breakdown
     *                                  (keys: total_active_stock, below_subject_count,
     *                                  above_subject_count)
     * @return array{
     *     key_drivers: string[],
     *     risk_factors: string[],
     *     position_summary: string,
     *     price_leverage_note: string
     * }
     */
    public function generate(
        MarketAnalyticsResult $ma,
        SaleProbabilityResult $sp,
        array $competitiveStock,
    ): array {
        $drivers = [];
        $risks   = [];

        // ── 1. Demand-supply ratio (stock pressure) ───────────────────────────
        $demandSupply = $ma->demandSupplyRatio;
        if ($demandSupply !== null) {
            if ($demandSupply >= 1.5) {
                $drivers[] = 'Strong buyer demand relative to available stock';
            } elseif ($demandSupply < 0.8) {
                $risks[] = 'High stock pressure — more properties listed than buyer demand supports';
            }
        }

        // ── 2. Months of inventory (absorption rate) ──────────────────────────
        $moi = $ma->monthsOfInventory;
        if ($moi !== null) {
            if ($moi <= 3) {
                $drivers[] = 'Fast-absorbing market (≤3 months of inventory)';
            } elseif ($moi >= 6) {
                $risks[] = 'Slow absorption — ' . round($moi, 1) . ' months of stock at current sales pace';
            }
        }

        // ── 3. Price per m² deviation ─────────────────────────────────────────
        $deviation = $ma->pricePerSqmDeviationPct;
        if ($deviation !== null) {
            if ($deviation < -5) {
                $drivers[] = 'Priced ' . round(abs($deviation), 1) . '% below median per-m² — strong value positioning';
            } elseif ($deviation > 5) {
                $risks[] = 'Priced ' . round($deviation, 1) . '% above market median per-m² — may limit buyer pool';
            }
        }

        // ── 4. Elasticity (price sensitivity) ────────────────────────────────
        $elasticity = $ma->elasticityDaysPerPct;
        $rSquared   = $ma->elasticityRSquared;
        if ($elasticity !== null && $rSquared !== null && $rSquared >= 0.5) {
            if (abs($elasticity) >= 3) {
                $drivers[] = 'Price is highly elastic — small reductions yield meaningful DOM improvement';
            }
        }

        // ── 5. DOM median ─────────────────────────────────────────────────────
        $domP50 = is_array($ma->domCurve) ? ($ma->domCurve['p50'] ?? null) : null;
        if ($domP50 !== null) {
            if ($domP50 <= 30) {
                $drivers[] = 'Properties sell quickly in this market (median ' . (int) $domP50 . ' days on market)';
            } elseif ($domP50 >= 90) {
                $risks[] = 'Slow market conditions — median days on market is ' . (int) $domP50;
            }
        }

        // ── 6. Competitive position summary ──────────────────────────────────
        $totalActive = $competitiveStock['total_active_stock'] ?? 0;
        $belowCount  = $competitiveStock['below_subject_count'] ?? null;
        $percentile  = ($totalActive > 0 && $belowCount !== null)
            ? $belowCount / $totalActive
            : null;

        if ($percentile === null) {
            $positionSummary = 'Competitive position could not be determined — no active stock data available.';
        } elseif ($percentile <= 0.25) {
            $positionSummary = 'Priced in the lower quartile of active listings — strong competitive position.';
        } elseif ($percentile <= 0.50) {
            $positionSummary = 'Priced below the median of active comparable listings — well-positioned.';
        } elseif ($percentile <= 0.75) {
            $positionSummary = 'Priced above the median of active comparable listings — moderate competitive pressure.';
        } else {
            $positionSummary = 'Priced in the top quartile of active listings — highest competitive pressure against ' . $totalActive . ' comparable properties.';
        }

        // ── 7. Price leverage note ────────────────────────────────────────────
        if ($elasticity !== null && $rSquared !== null && $rSquared >= 0.4 && abs($elasticity) >= 1) {
            $priceLeverageNote = 'A 1% price reduction is estimated to reduce time on market by ~'
                . round(abs($elasticity), 1) . ' days based on market elasticity data.';
        } elseif ($deviation !== null) {
            if ($deviation > 0) {
                $priceLeverageNote = 'At ' . round($deviation, 1) . '% above the median price per m², '
                    . 'aligning closer to market median may broaden buyer interest.';
            } elseif ($deviation < 0) {
                $priceLeverageNote = 'At ' . round(abs($deviation), 1) . '% below median price per m², '
                    . 'this property offers clear value relative to comparable stock.';
            } else {
                $priceLeverageNote = 'This property is priced at the market median per m².';
            }
        } else {
            $priceLeverageNote = 'Insufficient data to calculate a price leverage estimate.';
        }

        return [
            'key_drivers'        => array_values(array_slice($drivers, 0, 3)),
            'risk_factors'       => array_values($risks),
            'position_summary'   => $positionSummary,
            'price_leverage_note' => $priceLeverageNote,
        ];
    }
}
