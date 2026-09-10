<?php

declare(strict_types=1);

namespace App\Events\RentalApplication;

use App\Events\AbstractDomainEvent;
use App\Models\RentalApplication;

/**
 * Fires when an authoriser declines a rental application.
 * Spec: .ai/specs/rental-applications.md — Contact status section.
 */
final class RentalApplicationDeclined extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalApplication $application,
        public readonly ?int $authoriserUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->application->agency_id; }
    public function actorUserId(): ?int { return $this->authoriserUserId; }
    public function subject(): ?array { return [RentalApplication::class, $this->application->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->application->contact_id];
    }
}
