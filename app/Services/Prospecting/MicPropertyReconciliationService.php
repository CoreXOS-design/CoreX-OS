<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;

/**
 * MIC ↔ property-pillar reconciliation (Johan 2026-08-14).
 *
 * The MIC "create property from address" / address-unlock write path historically deduped against
 * `properties` by raw address-string equality only — so a property the canonical identity spine
 * WOULD resolve (by source-ref, GPS ~5m, erf+suburb, normalised structured address, or token
 * overlap) but whose free-text `address` differs — e.g. a deeds-promoted property — was missed and
 * MIC minted a DUPLICATE.
 *
 * This resolves an incoming MIC address against the SAME TrackedProperty match-or-create spine that
 * deeds promote uses, and — when the resolved TrackedProperty is already promoted to a live property
 * — returns that canonical Property so MIC reconciles (refresh) instead of duplicating.
 *
 * READ-ONLY on the matcher: it calls cc5's TrackedPropertyMatchOrCreateService::findExistingMatch
 * (which creates nothing); it never edits that service.
 */
class MicPropertyReconciliationService
{
    public function __construct(private readonly TrackedPropertyMatchOrCreateService $matcher) {}

    /**
     * Resolve an existing canonical Property for the given address facts, via the TrackedProperty
     * identity spine → its promoted property. Returns null when there is no existing match or the
     * matched TrackedProperty has not been promoted to a live property yet.
     *
     * @param array<string,mixed> $facts  address/gps/erf keys (same shape the matcher expects)
     */
    public function resolveExistingProperty(int $agencyId, array $facts): ?Property
    {
        $tp = $this->matcher->findExistingMatch($agencyId, $facts);
        if ($tp === null) {
            return null;
        }

        // Direct hit — the matched TrackedProperty is itself promoted to a live property.
        if (!empty($tp->promoted_to_property_id)) {
            $p = $this->liveProperty((int) $tp->promoted_to_property_id);
            if ($p) {
                return $p;
            }
        }

        // DUPLICATE-TP SAFETY (QA1 reality): the same physical asset can carry several
        // TrackedProperty rows (an import/matcher artifact), and findExistingMatch may return an
        // UNPROMOTED twin even though a sibling IS promoted to the canonical property. Resolve to the
        // promoted sibling by the matched TP's own canonical identity (erf+suburb, else
        // street_number+street_name+suburb) so MIC still reconciles to the one canonical property
        // instead of minting a duplicate. Uses the matched row's normalised keys — never invents new
        // matching semantics beyond what the matcher already produced.
        $hasErf    = !empty($tp->erf_number) && !empty($tp->suburb_normalised);
        $hasStreet = !empty($tp->street_number) && !empty($tp->street_name) && !empty($tp->suburb_normalised);
        if (! $hasErf && ! $hasStreet) {
            return null;
        }

        $sibling = TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNotNull('promoted_to_property_id')
            ->where(function ($q) use ($tp, $hasErf, $hasStreet) {
                if ($hasErf) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('erf_number', $tp->erf_number)
                        ->where('suburb_normalised', $tp->suburb_normalised));
                }
                if ($hasStreet) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('street_number', $tp->street_number)
                        ->where('street_name', $tp->street_name)
                        ->where('suburb_normalised', $tp->suburb_normalised));
                }
            })
            ->orderByDesc('promoted_at')
            ->first();

        if ($sibling && $sibling->promoted_to_property_id) {
            return $this->liveProperty((int) $sibling->promoted_to_property_id);
        }

        return null;
    }

    private function liveProperty(int $propertyId): ?Property
    {
        return Property::withoutGlobalScopes()->whereNull('deleted_at')->find($propertyId);
    }
}
