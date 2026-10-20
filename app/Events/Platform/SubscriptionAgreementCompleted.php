<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a CoreX Subscription Agreement is fully signed - countersigned online or on the hand-signed copy
 * (AgreementService::countersign / countersignWetInk), for an agreement linked to an agency. Unlike AgencyContractSigned it does
 * not depend on an onboarding timeline. Subscribers: Billing\ApplyAgreedQuotedFee (Johan 2026-10-20: the quoted fee for more than
 * the last band carries into billing automatically).
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5. Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class SubscriptionAgreementCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $agencyId,
        public readonly int $documentId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
}
