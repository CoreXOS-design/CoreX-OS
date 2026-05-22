<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\Presentation;

/**
 * Phase 3e E — auto-fill holding-cost components on a Presentation when the
 * agent hasn't entered explicit values.
 *
 * Inputs (any of which may be null):
 *   - presentation.asking_price_inc (preferred)  OR  cma.middle_range field
 *   - presentation.property_type      (sectional → add levies; full → skip)
 *   - presentation.floor_area_m2      (for sectional levies)
 *
 * Outputs (only writes columns currently null on the presentation):
 *   monthly_rates, monthly_levies, monthly_insurance,
 *   monthly_utilities, monthly_opportunity_cost.
 *
 * Bond payment is NOT auto-estimated — depends on the seller's outstanding
 * balance which we can't infer.
 *
 * Deliberately conservative: per-municipality precision is out of scope
 * (CLAUDE.md non-negotiable #6 says "no half-built infrastructure"). The
 * agency-level defaults give the agent a starting position they can tune.
 */
final class HoldingCostEstimator
{
    /**
     * Auto-fill nullable monthly_* columns on the presentation and return
     * a summary array of what was written.
     *
     * @return array{
     *   property_value: ?int,
     *   wrote: array<string, int|float>,
     *   skipped: array<string, string>,
     * }
     */
    public function estimateAndPersist(Presentation $presentation): array
    {
        $agency = $presentation->agency_id ? Agency::find($presentation->agency_id) : null;

        $askingPrice = $presentation->asking_price_inc !== null
            ? (int) $presentation->asking_price_inc
            : $this->cmaMiddleFromFields($presentation);

        $writes  = [];
        $skipped = [];

        if ($askingPrice === null || $askingPrice <= 0) {
            $skipped['all'] = 'no asking price or CMA middle available';
            return ['property_value' => null, 'wrote' => [], 'skipped' => $skipped];
        }

        $millions   = $askingPrice / 1_000_000;
        $isSection  = $this->isSectional((string) $presentation->property_type);
        $floorM2    = $presentation->floor_area_m2 ? (int) $presentation->floor_area_m2 : null;

        $ratesRate    = (int) ($agency?->presentations_default_rates_per_million_zar         ?? 800);
        $leviesPerM2  = (int) ($agency?->presentations_default_levies_sectional_per_m2_zar   ?? 25);
        $insuranceRate = (int) ($agency?->presentations_default_insurance_per_million_zar    ?? 200);
        $utilitiesFlat = (int) ($agency?->presentations_default_utilities_zar                ?? 1200);
        $oppCostPct    = (float) ($agency?->presentations_default_opportunity_cost_pct       ?? 8.0);

        $candidates = [
            'monthly_rates'            => (int) round($millions * $ratesRate),
            'monthly_levies'           => $isSection && $floorM2
                ? (int) round($floorM2 * $leviesPerM2)
                : 0,
            'monthly_insurance'        => (int) round($millions * $insuranceRate),
            'monthly_utilities'        => $utilitiesFlat,
            'monthly_opportunity_cost' => (int) round(($askingPrice * $oppCostPct / 100) / 12),
        ];

        $dirty = false;
        foreach ($candidates as $col => $value) {
            $current = $presentation->{$col};
            if ($current !== null && $current !== '' && (float) $current !== 0.0) {
                $skipped[$col] = 'agent value present';
                continue;
            }
            // Skip levies entirely for non-sectional (value=0 isn't a useful estimate).
            if ($col === 'monthly_levies' && !$isSection) {
                $skipped[$col] = 'not sectional';
                continue;
            }
            if ($value <= 0) {
                $skipped[$col] = 'computed zero';
                continue;
            }
            $presentation->{$col} = $value;
            $writes[$col]         = $value;
            $dirty                = true;
        }

        if ($dirty) {
            $presentation->save();
        }

        return [
            'property_value' => $askingPrice,
            'wrote'          => $writes,
            'skipped'        => $skipped,
        ];
    }

    private function cmaMiddleFromFields(Presentation $presentation): ?int
    {
        $field = $presentation->fields()
            ->where('field_key', 'cma.middle_range')
            ->first();
        if (!$field) return null;
        $val = (int) preg_replace('/[^\d]/', '', (string) ($field->final_value ?? ''));
        return $val > 0 ? $val : null;
    }

    private function isSectional(string $type): bool
    {
        $t = strtolower(trim($type));
        return $t !== '' && (
            str_contains($t, 'sectional')
            || str_contains($t, 'apartment')
            || str_contains($t, 'flat')
            || str_contains($t, 'duplex')
            || str_contains($t, 'townhouse')
            || str_contains($t, 'unit')
        );
    }
}
