<?php

declare(strict_types=1);

namespace App\Events\RentalApplication;

use App\Events\AbstractDomainEvent;
use App\Models\RentalApplication;

/**
 * Fires when an applicant submits or resubmits a rental application online.
 * Spec: .ai/specs/rental-applications.md — Contact status section.
 */
final class RentalApplicationSubmitted extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalApplication $application,
        public readonly bool $isResubmit,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->application->agency_id; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [RentalApplication::class, $this->application->id]; }

    public function context(): array
    {
        return [
            'contact_id' => $this->application->contact_id,
            'status' => $this->application->status,
            'is_resubmit' => $this->isResubmit,
        ];
    }
}
