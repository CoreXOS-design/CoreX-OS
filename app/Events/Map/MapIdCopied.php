<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;

/**
 * Phase A.2.4 — agent clicked "Copy ID" on a sensitive fact (typically the
 * owner ID number on a sectional scheme owner record). We log the click so
 * we can audit unmasked-PII access at the activity-stream level.
 *
 * event_type (derived by LogAgentActivity::eventTypeKey):
 *   `map_id.copied`
 *
 * No subject model — the record is keyed by record_id (an integer for
 * scheme_owners, a layer-prefixed string for comps). subject() stays null.
 */
final class MapIdCopied extends AbstractDomainEvent
{
    public function __construct(
        public readonly string $recordId,       // integer scheme_owners.id or 'mrcr:N' etc.
        public readonly string $category,       // 'scheme_owners' | 'sold_comps' | ...
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

    public function context(): array
    {
        return [
            'record_id'    => $this->recordId,
            'category'     => $this->category,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
