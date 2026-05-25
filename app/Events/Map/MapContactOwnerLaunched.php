<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\SchemeOwner;

/**
 * Phase A.2 — agent clicked "Contact owner" on a scheme_owner record.
 * Subject is the SchemeOwner row (not a Contact — scheme owners aren't
 * auto-promoted to Contact records until the agent commits to outreach).
 *
 * event_type: `map_contact_owner.launched`
 */
final class MapContactOwnerLaunched extends AbstractDomainEvent
{
    public function __construct(
        public readonly SchemeOwner $owner,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        public readonly string $channel,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        return [SchemeOwner::class, (int) $this->owner->id];
    }

    public function context(): array
    {
        return [
            'scheme_owner_id' => (int) $this->owner->id,
            'scheme_name'     => $this->owner->scheme_name,
            'channel'         => $this->channel,
            'location_key'    => $this->locationKey,
            'source'          => $this->source,
        ];
    }
}
