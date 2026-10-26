<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalFaultReport;

/**
 * A fault the tenant reported reached a step the tenant is told about (sent to owner / owner approved / not approved / completed — the
 * keys of RentalFaultProgressService). AT-508 P1. Dispatched by RentalPortalNotificationService::notifyTenantStatusChanged().
 */
final class RentalTenantFaultStatusChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalFaultReport $fault,
        public readonly string $stepKey,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->fault->agency_id; }
    public function actorUserId(): ?int { return null; }
    public function subject(): ?array { return [RentalFaultReport::class, $this->fault->id]; }

    public function context(): array
    {
        return ['step' => $this->stepKey];
    }
}
