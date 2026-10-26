<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalInspection;

/**
 * A completed inspection's report has been filed and is open to its parties (the tenant and the owner can now read it in their app).
 * AT-508. Dispatched by RentalInspectionCopiesService::fileAndSend(), which runs when an inspection is completed (and again on a manual
 * resend - the listener tells each phone once per inspection).
 */
final class RentalInspectionReportReady extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalInspection $inspection,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->inspection->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalInspection::class, $this->inspection->id]; }

    public function context(): array
    {
        return ['type' => $this->inspection->type, 'lease_id' => $this->inspection->lease_id];
    }
}
