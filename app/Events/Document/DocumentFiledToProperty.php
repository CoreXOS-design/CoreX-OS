<?php

declare(strict_types=1);

namespace App\Events\Document;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a Document is linked to a Property (a row in document_properties) AND the document's
 * type is one Road to Sold counts as a signed stock document: the mandate and the offer to purchase (`otp`, a paper OTP
 * uploaded onto the property or an e-signed one filed - DocumentPropertyObserver::COUNTED_TYPE_SLUGS). branchId and dealId
 * (the deal the document is filed against, when it has one) were added in the phase 0 remainder.
 *
 * Raised at the link itself, so it covers every way a mandate reaches a property: uploaded to the
 * property's files, uploaded from a contact, filed by the PDF splitter, or filed by a completed
 * e-sign. Johan, 20 Oct 2026 (answer 2): either counts. Spec: road-to-sold.md step 6.
 */
final class DocumentFiledToProperty extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $propertyId,
        public readonly string $documentTypeSlug,
        public readonly ?int $agencyId,
        public readonly ?int $actorUserId = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $branchId = null,
        public readonly ?int $dealId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\Document', $this->documentId];
    }
}
