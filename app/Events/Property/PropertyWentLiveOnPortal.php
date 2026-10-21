<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * Fires the first time a property goes live on a listing portal: `p24_activated_at` or
 * `pp_activated_at` goes from empty to stamped. One event per portal per property; a later refresh
 * or re-publish (which re-stamps an existing value) does not raise it. Not the same as
 * PropertyPublished, which is the agency's own website. Only a listing CoreX itself submitted counts
 * (the portal's *_last_submitted_at is set): stock an import brings in did not go live through the agent's work. Raised by PropertyObserver::updated().
 * Spec: road-to-sold.md step 7.
 */
final class PropertyWentLiveOnPortal extends AbstractDomainEvent
{
    public const PORTAL_P24 = 'p24';
    public const PORTAL_PRIVATE_PROPERTY = 'pp';

    public function __construct(
        public readonly Property $property,
        public readonly string $portal,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id !== null ? (int) $this->property->agency_id : null; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [Property::class, $this->property->getKey()];
    }
}
