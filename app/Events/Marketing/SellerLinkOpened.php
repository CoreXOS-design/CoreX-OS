<?php

declare(strict_types=1);

namespace App\Events\Marketing;

use App\Events\AbstractDomainEvent;

/**
 * Fires once per seller link, the FIRST time the seller really opens it - the only proof the seller saw it. Raised by
 * SellerLinkController::show() after the visit is recorded. Revoked / sold / unavailable links, repeat visits and link-preview
 * robots (WhatsApp, Facebook and similar fetch a link the moment it is pasted) raise nothing. There is no actor: the
 * visitor is the seller. userId = the agent the link was made by (the property's agent when CoreX made the link itself).
 * Spec: road-to-sold.md step 8b.
 */
final class SellerLinkOpened extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $linkId,
        public readonly int $propertyId,
        public readonly ?int $contactId,
        public readonly ?int $agencyId,
        public readonly ?int $branchId,
        public readonly ?int $userId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }

    public function subject(): ?array
    {
        return ['App\\Models\\PropertySellerLink', $this->linkId];
    }
}
