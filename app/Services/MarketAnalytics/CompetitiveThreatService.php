<?php

namespace App\Services\MarketAnalytics;

use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use Carbon\Carbon;

/**
 * Competitive Threat Ranking Engine (C3).
 *
 * Ranks competing active listings by threat level to the subject property.
 * Threat is scored by: price proximity, size proximity, DOM freshness,
 * price reductions, and data quality.
 *
 * Deterministic scoring. No DB writes. No AI.
 */
class CompetitiveThreatService
{
    /**
     * @param  Presentation $presentation
     * @param  float|null   $subjectPrice   Subject asking price (null = skip price proximity)
     * @param  int|null     $subjectSizeM2  Subject floor area (null = skip size proximity)
     * @param  int          $limit          Max threats to return
     *
     * @return array{threats: array}
     */
    public function rankThreats(
        Presentation $presentation,
        ?float $subjectPrice = null,
        ?int $subjectSizeM2 = null,
        int $limit = 5,
    ): array {
        $listings = PresentationActiveListing::where('presentation_id', $presentation->id)
            ->where('is_active', true)
            ->get();

        $scored = [];

        foreach ($listings as $listing) {
            $score  = 0;
            $factors = [];

            // ── 1. Price proximity (±5% = max 30 pts) ────────────────────
            if ($subjectPrice !== null && $subjectPrice > 0 && $listing->list_price_inc !== null) {
                $priceDiff = abs($listing->list_price_inc - $subjectPrice) / $subjectPrice;
                if ($priceDiff <= 0.05) {
                    $score += 30;
                    $factors[] = 'price_proximity';
                } elseif ($priceDiff <= 0.10) {
                    $score += 15;
                } elseif ($priceDiff <= 0.15) {
                    $score += 5;
                }
            }

            // ── 2. Size proximity (±10% = max 20 pts) ────────────────────
            if ($subjectSizeM2 !== null && $subjectSizeM2 > 0 && $listing->size_m2 !== null) {
                $sizeDiff = abs($listing->size_m2 - $subjectSizeM2) / $subjectSizeM2;
                if ($sizeDiff <= 0.10) {
                    $score += 20;
                    $factors[] = 'size_proximity';
                } elseif ($sizeDiff <= 0.20) {
                    $score += 10;
                } elseif ($sizeDiff <= 0.30) {
                    $score += 5;
                }
            }

            // ── 3. DOM freshness (max 25 pts) ────────────────────────────
            $domBucket = $this->domBucket($listing);
            switch ($domBucket) {
                case 'fresh':
                    $score += 25;
                    $factors[] = 'fresh_listing';
                    break;
                case 'normal':
                    $score += 15;
                    break;
                case 'aging':
                    $score += 5;
                    break;
                // 'stale' = 0 pts
            }

            // ── 4. Recent price reduction (max 15 pts) ───────────────────
            $hasPriceReduction = $this->hasPriceReduction($listing);
            if ($hasPriceReduction) {
                $score += 15;
                $factors[] = 'price_reduction';
            }

            // ── 5. Data quality (max 10 pts) ─────────────────────────────
            $dqScore = $listing->data_quality_score ?? 0;
            if ($dqScore > 80) {
                $score += 10;
            } elseif ($dqScore > 50) {
                $score += 5;
            }

            $scored[] = [
                'listing_id'      => $listing->id,
                'threat_score'    => min(100, $score),
                'price'           => $listing->list_price_inc,
                'size_m2'         => $listing->size_m2,
                'dom_bucket'      => $domBucket,
                'price_reduction' => $hasPriceReduction,
                'factors'         => $factors,
            ];
        }

        // Sort descending by threat_score
        usort($scored, fn($a, $b) => $b['threat_score'] <=> $a['threat_score']);

        return [
            'threats' => array_slice($scored, 0, $limit),
        ];
    }

    /**
     * Determine DOM bucket: fresh (0–14), normal (15–45), aging (46–90), stale (90+).
     */
    private function domBucket(PresentationActiveListing $listing): string
    {
        $listingDate = $listing->listing_date ?? $listing->first_seen_at;
        if ($listingDate === null) {
            return 'stale';
        }

        $dom = Carbon::parse($listingDate)->diffInDays(Carbon::today());

        if ($dom <= 14) {
            return 'fresh';
        }
        if ($dom <= 45) {
            return 'normal';
        }
        if ($dom <= 90) {
            return 'aging';
        }

        return 'stale';
    }

    /**
     * Check if listing has had a price reduction via price history.
     */
    private function hasPriceReduction(PresentationActiveListing $listing): bool
    {
        $history = $listing->priceHistory;

        if ($history->count() < 2) {
            return false;
        }

        $prices = $history->pluck('price_inc')->toArray();

        // A reduction exists if any subsequent price is lower than a prior one
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i] < $prices[$i - 1]) {
                return true;
            }
        }

        return false;
    }
}
