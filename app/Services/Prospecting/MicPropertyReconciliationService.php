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
        // 2026-10-07 (structured address matching, step 6) — the sibling lookup was exact column equality on erf
        // or on the street NAME (type included, number only when in its own column). It is now the scorer's EXACT
        // tier against the matched row — erf (+ portion / LPI), scheme + unit, or number + street + suburb, with
        // the same vetoes everything else uses — restricted to siblings already promoted to a property.
        $siblings = [];
        foreach (app(\App\Services\Address\AddressMatcher::class)->trackedProperties(
            $agencyId, \App\Services\Address\AddressFacts::fromModel($tp), null, [(int) $tp->id]
        ) as $h) {
            if ($h['result']['tier'] === \App\Services\Address\AddressMatchScorer::TIER_EXACT && ! empty($h['model']->promoted_to_property_id)) {
                $siblings[] = $h['model'];
            }
        }
        usort($siblings, fn ($x, $y) => [$y->promoted_at?->getTimestamp() ?? 0, $y->id] <=> [$x->promoted_at?->getTimestamp() ?? 0, $x->id]);
        $sibling = $siblings[0] ?? null;

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
