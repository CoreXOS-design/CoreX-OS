<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;

/**
 * Phase A.2 — agent clicked "Use as comparable" on a sold-comp record
 * from the map. The map doesn't have a presentation context, so this
 * event records the INTENT — the agent's next click in the destination
 * page will be the actual comp add. We still log here because the map is
 * the entry point and the conversion funnel starts here.
 *
 * Subject is the comp source row (market_report_comp_rows or
 * presentation_sold_comps), keyed by the layer prefix (mrcr:/psc:/deal:).
 *
 * event_type: `map_comparable.added`
 */
final class MapComparableAdded extends AbstractDomainEvent
{
    public function __construct(
        public readonly string $compRef,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        // No single canonical model — comp ref is a colon-prefixed string
        // (mrcr:123, psc:45, deal:9). Subject stays null and the ref lives
        // in the payload so the activity row is still queryable.
        return null;
    }

    public function context(): array
    {
        return [
            'comp_ref'     => $this->compRef,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
