<?php

declare(strict_types=1);

namespace App\Events\Mandate;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a mandate is signed. The "mandate" subject in CoreX is currently
 * a Deal (mandate-type deal) or a Property promoted from tracked → stock; this
 * event accepts a polymorphic mandate model + optional related property.
 *
 * Road to Sold phase 0 (build log P8): now raised for real - once per e-signed mandate, at the moment the
 * signing is COMPLETED (SignatureService::completeDocument, after commit), with the e-sign document as `mandate`
 * and the property it is for. It does not wait for the PDF to be filed (that is DocumentFiledToProperty, later).
 * userId = the agent who sent it for signing; branchId = the document's branch.
 */
final class MandateSigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $mandate,
        public readonly ?Model $property = null,
        public readonly ?int $agencyIdHint = null,
        public readonly ?int $actorUserId = null,
        public readonly ?int $userId = null,
        public readonly ?int $branchId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdHint
            ?? ($this->mandate->agency_id ?? null)
            ?? ($this->property->agency_id ?? null);
    }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [get_class($this->mandate), $this->mandate->getKey()];
    }

    /**
     * Ids only. The default snapshot stores every attribute of each model property, and an e-sign document carries its whole
     * merged and signed HTML - far too heavy for a row in domain_event_log.
     */
    public function payloadSnapshot(): array
    {
        return [
            'mandateClass'  => get_class($this->mandate),
            'mandateId'     => $this->mandate->getKey(),
            'propertyId'    => $this->property?->getKey(),
            'agencyIdHint'  => $this->agencyIdHint,
            'actorUserId'   => $this->actorUserId,
            'userId'        => $this->userId,
            'branchId'      => $this->branchId,
        ];
    }

    public function context(): array
    {
        return [
            'property_id' => $this->property?->getKey(),
            'property_class' => $this->property ? get_class($this->property) : null,
        ];
    }
}
