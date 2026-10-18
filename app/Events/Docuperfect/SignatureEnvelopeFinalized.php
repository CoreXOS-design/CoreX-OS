<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\SignatureTemplate;

/**
 * An e-sign envelope was completed and its signed document finalised and filed. Spec: .ai/specs/leases.md §15.15 (Build L1 — declared; Build L3b emits it from the e-sign
 * engine). Scalars only, so a listener fault can never disturb a legally completed signing.
 */
final class SignatureEnvelopeFinalized extends AbstractDomainEvent
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
