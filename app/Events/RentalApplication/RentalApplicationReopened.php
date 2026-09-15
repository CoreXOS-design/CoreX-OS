<?php

declare(strict_types=1);

namespace App\Events\RentalApplication;

use App\Events\AbstractDomainEvent;
use App\Models\RentalApplication;

/**
 * Fires when an application is reopened (agent from returned/under_assessment,
 * or the CO/admin override tier from declined).
 * Spec: .ai/specs/rental-applications.md — Contact status section.
 */
final class RentalApplicationReopened extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalApplication $application,
        public readonly bool $isOverride,
        public readonly ?int $actorId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->application->agency_id; }
    public function actorUserId(): ?int { return $this->actorId; }
    public function subject(): ?array { return [RentalApplication::class, $this->application->id]; }

    public function context(): array
    {
        return [
            'contact_id' => $this->application->contact_id,
            'is_override' => $this->isOverride,
        ];
    }
}
