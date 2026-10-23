<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;

/**
 * Fires once per e-signed offer to purchase (document type `otp`), when its envelope is signed, approved AND filed - the
 * same moment SignatureEnvelopeFinalized is announced. Raised by RaiseOtpSignedFromEnvelope, which listens for that event
 * and only passes the OTP ones on. A paper OTP is a different route: it raises DocumentFiledToProperty (type `otp`) when
 * uploaded onto the property. userId = the agent who sent it for signing. dealId is the deal the signed copy was filed
 * against, when CoreX could tell. Spec: road-to-sold.md step 11.
 */
final class OtpSigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $signatureTemplateId,
        public readonly ?int $documentId,
        public readonly ?int $propertyId,
        public readonly ?int $dealId,
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
        return ['App\\Models\\Docuperfect\\SignatureTemplate', $this->signatureTemplateId];
    }
}
