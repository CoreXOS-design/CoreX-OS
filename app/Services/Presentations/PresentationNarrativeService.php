<?php

namespace App\Services\Presentations;

use App\Services\HoldingCost\HoldingCostService;
use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;
use App\Services\SaleProbability\DTOs\SaleProbabilityResult;

/**
 * Translates engine outputs into a seller-facing narrative for presentation meetings.
 *
 * All logic is deterministic: same inputs always produce the same output.
 * No math lives here — only copy and interpretation of pre-computed engine outputs.
 */
class PresentationNarrativeService
{
    /**
     * All five SP signal names, in display order.
     */
    private const SIGNAL_DEFINITIONS = [
        'price' => [
            'label'      => 'Price Position',
            'skip_reason' => 'Price/m² comparison requires both asking price and floor area.',
            'how_to_fix' => 'Add asking price and floor area in the inputs panel above.',
        ],
        'absorption' => [
            'label'      => 'Market Inventory',
            'skip_reason' => 'Insufficient sold transactions to compute absorption rate.',
            'how_to_fix' => 'Import more sold listings for this suburb.',
        ],
        'pressure' => [
            'label'      => 'Demand vs Supply',
            'skip_reason' => 'Active listing count could not be determined.',
            'how_to_fix' => 'Ensure active listings have been imported and are up to date.',
        ],
        'dom' => [
            'label'      => 'Days on Market',
            'skip_reason' => 'Not enough sold data to compute median DOM.',
            'how_to_fix' => 'Import more sold listings for this suburb.',
        ],
        'elasticity' => [
            'label'      => 'Price Elasticity',
            'skip_reason' => 'Insufficient price/days pairs to fit elasticity curve.',
            'how_to_fix' => 'Import at least 5 sold listings with both price and days-on-market data.',
        ],
    ];

    /**
     * Build a seller-facing narrative array.
     *
     * @param  array<string, mixed>  $inputs  Validated form inputs (suburb, type, price, etc.)
     *
     * @return array{
     *     headline: string,
     *     confidence_state: 'good'|'limited'|'none',
     *     what_this_means: string[],
     *     next_best_actions: string[],
     *     pricing_message: string,
     *     holding_cost_message: string,
     *     evidence_summary: array<string, string|null>,
     *     signal_status: array<string, array{label: string, active: bool, skip_reason: string, how_to_fix: string}>
     * }
     */
    public function build(
        MarketAnalyticsResult $ma,
        SaleProbabilityResult $sp,
        ?HoldingCostService $holdingCost,
        array $inputs,
    ): array {
        $breakdown     = $sp->toBreakdownArray();
        $rawSignals    = $breakdown['signals'] ?? [];
        $activeCount   = count(array_filter($rawSignals, fn($s) => !($s['skip'] ?? true)));

        $confidence = $this->resolveConfidence($sp, $activeCount);
        $suburb     = $inputs['suburb'] ?? 'this suburb';
        $period     = (int) ($inputs['period_months'] ?? 12);

        return [
            'headline'             => $this->headline($sp, $confidence),
            'confidence_state'     => $confidence,
            'what_this_means'      => $this->whatThisMeans($ma, $sp, $confidence, $suburb, $period, $activeCount),
            'next_best_actions'    => $this->nextBestActions($ma, $sp, $confidence, $suburb, $inputs, $holdingCost),
            'pricing_message'      => $this->pricingMessage($sp, $confidence),
            'holding_cost_message' => $this->holdingCostMessage($holdingCost, $sp),
            'evidence_summary'     => $this->evidenceSummary($ma, $sp),
            'signal_status'        => $this->signalStatus($rawSignals),
        ];
    }

    // ── confidence ───────────────────────────────────────────────────────────

    private function resolveConfidence(SaleProbabilityResult $sp, int $activeCount): string
    {
        if ($sp->skipReason !== null || $sp->p60 === null) {
            return 'none';
        }

        if ($activeCount < 3) {
            return 'limited';
        }

        return 'good';
    }

    // ── headline ─────────────────────────────────────────────────────────────

    private function headline(SaleProbabilityResult $sp, string $confidence): string
    {
        if ($confidence === 'none') {
            return "We need more market data before we can build your report";
        }

        $p60 = $sp->p60;

        if ($confidence === 'limited') {
            if ($p60 >= 0.65) {
                return 'Early signals look positive — more data would strengthen this';
            }
            if ($p60 >= 0.35) {
                return 'Partial signals detected — a fuller picture is needed';
            }
            return 'Limited data — early signs suggest pricing may need attention';
        }

        // good
        if ($p60 >= 0.65) {
            return 'Strong position — this property is well-priced for the market';
        }
        if ($p60 >= 0.35) {
            return 'Competitive pricing — a small adjustment could improve your chances';
        }
        return 'Price adjustment recommended — the market is signalling resistance';
    }

    // ── what this means ──────────────────────────────────────────────────────

    private function whatThisMeans(
        MarketAnalyticsResult $ma,
        SaleProbabilityResult $sp,
        string $confidence,
        string $suburb,
        int $period,
        int $activeCount,
    ): array {
        if ($confidence === 'none') {
            return [
                "No comparable sold listings were found in {$suburb} for the selected {$period}-month window.",
                'Without sold transaction data, sale probability and expected timing cannot be computed.',
                'Once market data is available, this screen will show probability, expected days, and pricing signals.',
            ];
        }

        $bullets = [];

        // Bullet 1: inventory / market conditions
        if ($ma->monthsOfInventory !== null) {
            $inv  = number_format($ma->monthsOfInventory, 1);
            $cond = $ma->monthsOfInventory <= 3 ? "a seller's market"
                : ($ma->monthsOfInventory <= 6 ? 'a balanced market'
                : "a buyer's market");
            $bullets[] = "The {$suburb} market has {$inv} months of inventory — indicating {$cond}.";
        } elseif ($ma->soldCount !== null) {
            $bullets[] = "{$ma->soldCount} sold transactions found in {$suburb} over {$period} months.";
        } else {
            $bullets[] = "Market inventory data is not yet available for {$suburb}.";
        }

        // Bullet 2: probability
        if ($sp->p60 !== null) {
            $pct = number_format($sp->p60 * 100, 0);
            if ($confidence === 'limited') {
                $bullets[] = "Based on partial signals ({$activeCount}/5 active), there is an estimated {$pct}% chance of selling within 60 days — treat as indicative.";
            } else {
                $bullets[] = "At this price, there is a {$pct}% chance of selling within 60 days.";
            }
        } else {
            $bullets[] = 'Sale probability could not be computed — more data is needed.';
        }

        // Bullet 3: timing / DOM
        $dom   = is_array($ma->domCurve) ? ($ma->domCurve['p50'] ?? null) : null;
        $dom75 = is_array($ma->domCurve) ? ($ma->domCurve['p75'] ?? null) : null;

        if ($sp->expectedDays !== null) {
            $domStr = ($dom !== null && $dom75 !== null) ? " (market median: {$dom}–{$dom75} days)" : '';
            $bullets[] = "At this price, the model expects a sale in approximately {$sp->expectedDays} days{$domStr}.";
        } elseif ($dom !== null) {
            $p75Str = $dom75 !== null ? " (75th percentile: {$dom75} days)" : '';
            $bullets[] = "The median time to sell in this area is {$dom} days{$p75Str}.";
        } else {
            $bullets[] = 'Days-on-market data is not yet available — import more sold listings to unlock this signal.';
        }

        return $bullets;
    }

    // ── next best actions ────────────────────────────────────────────────────

    private function nextBestActions(
        MarketAnalyticsResult $ma,
        SaleProbabilityResult $sp,
        string $confidence,
        string $suburb,
        array $inputs,
        ?HoldingCostService $holdingCost,
    ): array {
        if ($confidence === 'none') {
            return [
                "Import recent sold listings for {$suburb} into the platform.",
                'Add floor area and bedroom count to enable price-per-m² comparison.',
                'Book a follow-up meeting once data has been loaded — re-run the analysis to unlock the full engine.',
            ];
        }

        if ($confidence === 'limited') {
            $actions = [
                "Import more sold listings for {$suburb} to activate all 5 pricing signals.",
                'Ensure floor area and asking price are filled in above for price/m² analysis.',
            ];

            if ($holdingCost !== null && $holdingCost->monthlyTotal() > 0) {
                $monthly = 'R' . number_format($holdingCost->monthlyTotal(), 0);
                $actions[] = "Factor in your {$monthly}/month holding cost when evaluating time-on-market tolerance.";
            } else {
                $actions[] = 'Add holding cost inputs above to understand the financial impact of a longer sale timeline.';
            }

            return $actions;
        }

        // confidence = good — tailor by probability tier
        $p60     = $sp->p60;
        $actions = [];

        if ($p60 >= 0.65) {
            $actions[] = 'Maintain current pricing — the market data supports your position.';
            $actions[] = 'Respond to enquiries promptly; buyer interest is likely at this price.';

            $domP75 = is_array($ma->domCurve) ? ($ma->domCurve['p75'] ?? null) : null;
            if ($domP75 !== null) {
                $actions[] = "Set a review trigger: if no offer by {$domP75} days on market, reassess pricing with fresh data.";
            } else {
                $actions[] = 'Monitor active competing listings weekly and adjust your strategy if the market shifts.';
            }
        } elseif ($p60 >= 0.35) {
            $recommendedDrop = $this->findRecommendedDrop($sp->sensitivity);
            if ($recommendedDrop !== null) {
                $dropStr   = 'R' . number_format(abs($recommendedDrop), 0);
                $actions[] = "Consider a {$dropStr} price reduction — this may move your sale probability above 65%.";
            } else {
                $actions[] = 'Review price sensitivity below — a modest reduction may significantly improve your position.';
            }

            $actions[] = 'Monitor active competing listings weekly to track how the market is moving.';

            $domP50 = is_array($ma->domCurve) ? ($ma->domCurve['p50'] ?? null) : null;
            if ($domP50 !== null) {
                $actions[] = "If no offer within {$domP50} days on market, run a fresh analysis with updated data.";
            } else {
                $actions[] = 'Add holding cost inputs to understand the true cost of a longer sale timeline.';
            }
        } else {
            // p60 < 0.35 — high urgency
            $recommendedDrop = $this->findRecommendedDrop($sp->sensitivity);
            if ($recommendedDrop !== null) {
                $dropStr   = 'R' . number_format(abs($recommendedDrop), 0);
                $actions[] = "A price reduction of {$dropStr} is recommended — without this, a sale within 60 days is unlikely.";
            } else {
                $actions[] = 'A meaningful price reduction is needed — current pricing is above market tolerance.';
            }

            $actions[] = 'Review the Price Sensitivity table below to find the price point that unlocks strong buyer demand.';

            if ($holdingCost !== null && $holdingCost->monthlyTotal() > 0) {
                $monthly = 'R' . number_format($holdingCost->monthlyTotal(), 0);
                $cost90  = 'R' . number_format($holdingCost->costForDays(90), 0);
                $actions[] = "Every month at this price costs you {$monthly} in carrying costs — a 90-day delay is {$cost90}.";
            } else {
                $actions[] = 'Add holding cost inputs to quantify the real cost of staying at the current price.';
            }
        }

        return $actions;
    }

    private function findRecommendedDrop(array $sensitivity): ?int
    {
        $best = null;
        foreach ($sensitivity as $row) {
            if (($row['delta_rands'] ?? 0) >= 0) {
                continue;
            }
            if (($row['p60'] ?? null) === null) {
                continue;
            }
            if ($row['p60'] >= 0.65) {
                if ($best === null || abs($row['delta_rands']) < abs($best)) {
                    $best = (int) $row['delta_rands'];
                }
            }
        }
        return $best;
    }

    // ── pricing message ──────────────────────────────────────────────────────

    private function pricingMessage(SaleProbabilityResult $sp, string $confidence): string
    {
        if ($confidence === 'none') {
            return 'Price assessment requires comparable sales data.';
        }

        if ($confidence === 'limited') {
            return 'Price assessment is based on partial data — treat as indicative only.';
        }

        $p60 = $sp->p60;

        if ($p60 >= 0.65) {
            return 'At this price, a sale within 60 days is likely.';
        }
        if ($p60 >= 0.35) {
            return 'Sale probability is moderate — the market is responsive to pricing at this level.';
        }
        return 'At this price, a sale within 60 days is unlikely without a meaningful price adjustment.';
    }

    // ── holding cost message ─────────────────────────────────────────────────

    private function holdingCostMessage(?HoldingCostService $holdingCost, SaleProbabilityResult $sp): string
    {
        if ($holdingCost === null || $holdingCost->monthlyTotal() <= 0) {
            return '';
        }

        $monthly = 'R' . number_format($holdingCost->monthlyTotal(), 0);
        $cost90  = 'R' . number_format($holdingCost->costForDays(90), 0);

        if ($sp->expectedDays !== null) {
            $projected = 'R' . number_format($holdingCost->costForDays($sp->expectedDays), 0);
            return "Every 30 days on market costs {$monthly}. A 90-day delay costs {$cost90}. At the expected sale time, you would carry {$projected} in holding costs.";
        }

        return "Every 30 days on market costs {$monthly}. A 90-day delay would cost {$cost90}.";
    }

    // ── evidence summary ─────────────────────────────────────────────────────

    private function evidenceSummary(MarketAnalyticsResult $ma, SaleProbabilityResult $sp): array
    {
        $dom = is_array($ma->domCurve) ? $ma->domCurve : [];

        return [
            'Months of Inventory' => $ma->monthsOfInventory !== null
                                        ? number_format($ma->monthsOfInventory, 1) . ' months'
                                        : null,
            'Demand / Supply'     => $ma->demandSupplyRatio !== null
                                        ? number_format($ma->demandSupplyRatio, 2) . '×'
                                        : null,
            'Active Listings'     => $ma->activeListingCount !== null
                                        ? (string) $ma->activeListingCount
                                        : null,
            'Sold Count'          => $ma->soldCount !== null
                                        ? (string) $ma->soldCount
                                        : null,
            'DOM p50'             => isset($dom['p50'])
                                        ? $dom['p50'] . ' days'
                                        : null,
            'DOM p75'             => isset($dom['p75'])
                                        ? $dom['p75'] . ' days'
                                        : null,
            'Price/m² Deviation'  => $ma->pricePerSqmDeviationPct !== null
                                        ? number_format($ma->pricePerSqmDeviationPct, 1) . '%'
                                        : null,
        ];
    }

    // ── signal status (Prompt 8: Data Adequacy) ──────────────────────────────

    /**
     * Returns a row for every known signal, active or skipped.
     * Used to render the Data Adequacy table.
     *
     * @return array<string, array{label: string, active: bool, skip_reason: string, how_to_fix: string}>
     */
    private function signalStatus(array $rawSignals): array
    {
        $status = [];

        foreach (self::SIGNAL_DEFINITIONS as $name => $def) {
            $signal = $rawSignals[$name] ?? null;
            $active = $signal !== null && !($signal['skip'] ?? true);

            $status[$name] = [
                'label'       => $def['label'],
                'active'      => $active,
                'skip_reason' => $active ? '' : $def['skip_reason'],
                'how_to_fix'  => $active ? '' : $def['how_to_fix'],
            ];
        }

        return $status;
    }
}
