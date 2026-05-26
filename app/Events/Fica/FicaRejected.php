<?php

declare(strict_types=1);

namespace App\Events\Fica;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\FicaSubmission;

/**
 * Fires when a FICA package is rejected.
 */
final class FicaRejected extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly FicaSubmission $package,
        public readonly ?string $reason,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? $this->package->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [FicaSubmission::class, $this->package->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->contact->id, 'reason' => $this->reason];
    }
}
