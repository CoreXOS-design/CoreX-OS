<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\TrackedProperty;

/**
 * Fires when the primary tracked_property_addresses row for a TP changes —
 * either because a new address was promoted, or the existing primary was
 * deleted and the next-highest-confidence address took its place.
 *
 * Spec §14.1.
 */
final class TrackedPropertyAddressPrimaryChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $trackedPropertyId,
        public readonly ?int $previousPrimaryAddressId,
        public readonly int $newPrimaryAddressId,
        public readonly int $agencyId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return [TrackedProperty::class, $this->trackedPropertyId];
    }

    public function context(): array
    {
        return [
            'previous_primary_address_id' => $this->previousPrimaryAddressId,
            'new_primary_address_id'      => $this->newPrimaryAddressId,
        ];
    }
}
