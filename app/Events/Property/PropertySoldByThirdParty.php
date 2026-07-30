<?php

declare(strict_types=1);

namespace App\Events\Property;

use App\Events\AbstractDomainEvent;
use App\Models\Property;

/**
 * AT-350 — a property we held was sold by ANOTHER agency.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §7
 * Catalogue: .ai/specs/corex-domain-events-spec.md (Non-negotiable #9 — cross-pillar
 * reactivity is announced with a named event, never an ad-hoc service call).
 *
 * Emitted once per LOSS EVENT, from ThirdPartySaleService — the single write path
 * both the rich capture form and the bare status-dropdown save funnel through — so
 * every ingress announces the loss identically.
 *
 * Deliberately NOT the trigger for portal delisting: PropertyObserver already
 * delists any property whose status turns off-market, and 'sold_by_3rd_party' is
 * in Property::OFF_MARKET_STATUSES. Duplicating that here would give one outcome
 * two owners.
 *
 * Payload is scalars beside the model, so a subscriber that needs to queue work
 * can hand a Job plain values. A queued LISTENER on a domain event fatals — the
 * parent's readonly $eventId cannot be restored from the child scope (AT-261) —
 * so subscribers stay sync and queue a Job carrying scalars instead.
 */
final class PropertySoldByThirdParty extends AbstractDomainEvent
{
    public function __construct(
        public readonly Property $property,
        public readonly int $thirdPartySaleId,
        public readonly ?string $soldByAgency = null,
        public readonly ?string $soldPrice = null,
        public readonly ?string $soldDate = null,
        public readonly ?string $lossReason = null,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->property->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Property::class, $this->property->id]; }

    public function context(): array
    {
        return [
            'third_party_sale_id' => $this->thirdPartySaleId,
            'sold_by_agency'      => $this->soldByAgency,
            'loss_reason'         => $this->lossReason,
        ];
    }
}
