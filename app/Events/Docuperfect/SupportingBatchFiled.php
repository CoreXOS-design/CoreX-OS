<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;

/**
 * Fires when the PDF Splitter has finished filing a batch of recipient
 * supporting-document uploads that were pulled in via the intake-by-reference
 * hook (POST /tools/pdf-splitter/intake-supporting), not a direct browser
 * upload. Carries the originating e-sign correlation (signature_request_id +
 * the SignedDocumentVersion ids that were pulled in) plus the Document ids
 * the splitter actually created, so a listener elsewhere can stamp those
 * source rows as filed without the splitter knowing anything about
 * signed_document_versions itself.
 *
 * No listener is registered here — this event only fires the signal. The
 * recipient-docs side (cc2) owns subscribing to it and stamping
 * SignedDocumentVersion::filed_at / filed_by_user_id.
 *
 * Spec: .ai/specs/pdf-splitter-routing.md — "Wiring hooks (intake by
 * reference)" addendum.
 */
final class SupportingBatchFiled extends AbstractDomainEvent
{
    /**
     * @param  int[]  $signedDocumentVersionIds  The SignedDocumentVersion ids pulled into this batch.
     * @param  int[]  $documentIds  The Document ids the splitter created while filing this batch.
     */
    public function __construct(
        public readonly int $signatureRequestId,
        public readonly array $signedDocumentVersionIds,
        public readonly array $documentIds,
        public readonly int $propertyId,
        public readonly ?int $actorUserIdValue,
        public readonly int $agencyIdValue,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdValue;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserIdValue;
    }

    public function subject(): ?array
    {
        return ['signature_requests', $this->signatureRequestId];
    }

    public function context(): array
    {
        return [
            'signature_request_id' => $this->signatureRequestId,
            'signed_document_version_ids' => $this->signedDocumentVersionIds,
            'document_ids' => $this->documentIds,
            'property_id' => $this->propertyId,
        ];
    }
}
