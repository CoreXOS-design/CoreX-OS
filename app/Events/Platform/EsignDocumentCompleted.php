<?php

declare(strict_types=1);

namespace App\Events\Platform;

use App\Events\AbstractDomainEvent;

/**
 * Fires when ANY Platform E-Sign document is fully signed (EsignService::complete(), after sealing and the timeline sync).
 * Carries the template kind so a subscriber reacts only to the contracts it owns - Solo\ActivateSoloAgentOnAgreementSigned
 * acts on kind 'solo_agent_agreement'. Not fired for a forced re-seal (nobody is re-notified). Idempotent subscribers only:
 * the recovery path (a never-sealed document) can complete a document a second time.
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md section 5. Payload is SCALARS ONLY; keep listeners SYNC.
 */
final class EsignDocumentCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $documentId,
        public readonly ?int $agencyId,
        public readonly ?string $templateKind,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
}
