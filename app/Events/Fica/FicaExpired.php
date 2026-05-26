<?php

declare(strict_types=1);

namespace App\Events\Fica;

use App\Events\AbstractDomainEvent;
use App\Models\Contact;
use App\Models\FicaSubmission;

/**
 * Fires when a previously-approved FICA package reaches its expiry date.
 */
final class FicaExpired extends AbstractDomainEvent
{
    public function __construct(
        public readonly Contact $contact,
        public readonly FicaSubmission $package,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->contact->agency_id ?? $this->package->agency_id ?? null; }
    public function subject(): ?array { return [FicaSubmission::class, $this->package->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->contact->id];
    }
}
