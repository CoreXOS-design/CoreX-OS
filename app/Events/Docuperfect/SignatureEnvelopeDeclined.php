<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\SignatureTemplate;

/**
 * A signer declined, or the agent rejected, an e-sign envelope (`reason` says which). Spec: .ai/specs/leases.md §15.15 (Build L1 — declared; Build L3b emits it from the e-sign
 * engine). Scalars only, so a listener fault can never disturb a legally completed signing.
 */
final class SignatureEnvelopeDeclined extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $signatureTemplateId,
        public readonly ?int $documentId,
        public readonly ?int $agencyId,
        public readonly ?string $reason = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function subject(): ?array { return [SignatureTemplate::class, $this->signatureTemplateId]; }
}
