<?php

declare(strict_types=1);

namespace App\Events\RentalApplication;

use App\Events\AbstractDomainEvent;
use App\Models\RentalApplication;

/**
 * Fires when an agent records that an applicant withdrew (RentalApplicationController::updateStatus).
 * Keeps Contact::rental_application_status in step (RecomputeRentalApplicationStatus).
 * Spec: .ai/specs/rental-applications.md - Contact status section.
 */
final class RentalApplicationWithdrawn extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalApplication $application,
        public readonly ?int $agentUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->application->agency_id; }
    public function actorUserId(): ?int { return $this->agentUserId; }
    public function subject(): ?array { return [RentalApplication::class, $this->application->id]; }

    public function context(): array
    {
        return ['contact_id' => $this->application->contact_id];
    }
}
