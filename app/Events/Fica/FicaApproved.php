<?php

declare(strict_types=1);

namespace App\Events\Fica;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\FicaSubmission;

/**
 * Fires when a FICA package is approved by a compliance officer.
 */
final class FicaApproved extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly FicaSubmission $package,
        public readonly ?int $approvedByUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? $this->package->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->approvedByUserId; }
    public function subject(): ?array { return [FicaSubmission::class, $this->package->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->contact->id, 'approved_by' => $this->approvedByUserId];
    }
}
