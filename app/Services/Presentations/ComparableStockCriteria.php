<?php

namespace App\Services\Presentations;

use App\Models\Property;

/**
 * AT-288 — the shared comparable-stock SELECTION CRITERIA, extracted from
 * CompetitorStockMatchService::buildCriteria() so both consumers apply ONE
 * vetted rule-set (bug-class fix, not a per-surface reimplementation):
 *
 *   - CompetitorStockMatchService::findCompetitors()      → competitive on-market
 *     stock from the scraped prospecting_listings pool (presentation CMA).
 *   - CompetitorStockMatchService::findComparableStock()  → own-agency on-market
 *     stock from `properties` (Property Intelligence "Comparable Listings").
 *
 * The rules: normalised-suburb scope, Level-1 title-family gate, price band
 * (±competitor_stock_default_price_tolerance_pct), beds tolerance
 * (±competitor_stock_default_beds_tolerance, NULL-permissive), comparability
 * scoring (weights) with a competitor_stock_min_score floor, capped by
 * competitor_stock_default_display_count. All thresholds are agency-configurable
 * on the Agency model. See .ai/specs/mic-complete-spec.md + presentations.md.
 */
final class ComparableStockCriteria
{
    public function __construct(
        public readonly int $agencyId,
        public readonly Property $subject,
        public readonly string $suburb,
        public readonly string $suburbCore,
        public readonly ?string $propertyType,
        public readonly ?string $subjectKind,
        public readonly string $family,
        /** @var string[] */
        public readonly array $familyTypes,
        public readonly ?int $beds,
        public readonly ?int $bedsMin,
        public readonly ?int $bedsMax,
        public readonly int $price,
        public readonly int $priceMin,
        public readonly int $priceMax,
        public readonly int $bedsTol,
        public readonly int $pricePct,
        /** @var array<string,int> */
        public readonly array $weights,
        public readonly int $minScore,
        public readonly int $displayCount,
    ) {
    }

    /**
     * The legacy array shape CompetitorStockMatchService::buildCriteria() has
     * always returned — kept byte-identical so loadCandidates(),
     * scoreComparability() and the manual picker are unchanged. (minScore /
     * displayCount are NOT part of this array; they were resolved separately by
     * findCompetitors and stay off the criteria array to preserve its shape.)
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'agency_id'     => $this->agencyId,
            'subject'       => $this->subject,
            'suburb'        => $this->suburb,
            'suburb_core'   => $this->suburbCore,
            'property_type' => $this->propertyType,
            'subject_kind'  => $this->subjectKind,
            'family'        => $this->family,
            'family_types'  => $this->familyTypes,
            'beds'          => $this->beds,
            'beds_min'      => $this->bedsMin,
            'beds_max'      => $this->bedsMax,
            'price'         => $this->price,
            'price_min'     => $this->priceMin,
            'price_max'     => $this->priceMax,
            'beds_tol'      => $this->bedsTol,
            'price_pct'     => $this->pricePct,
            'weights'       => $this->weights,
        ];
    }
}
