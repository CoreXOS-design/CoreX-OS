<?php

declare(strict_types=1);

namespace App\Events\RentalApplication;

use App\Events\AbstractDomainEvent;
use App\Models\RentalApplication;

/**
 * Fires when an agent takes a reopened application back from the applicant without a resubmission
 * (RentalApplicationController::cancelReopen, audit fix A3 2026-10-19).
 * Keeps Contact::rental_application_status in step (RecomputeRentalApplicationStatus).
 * Spec: .ai/specs/rental-applications.md - Contact status section, "Audit fixes 2026-10-19".
 */
final class RentalApplicationReopenCancelled extends AbstractDomainEvent
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
        return ['contact_id' => $this->application->contact_id, 'status' => $this->application->status];
    }
}
