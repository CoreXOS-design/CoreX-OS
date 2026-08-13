<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;

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
        if ($tp === null || empty($tp->promoted_to_property_id)) {
            return null;
        }

        return Property::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find((int) $tp->promoted_to_property_id);
    }
}
