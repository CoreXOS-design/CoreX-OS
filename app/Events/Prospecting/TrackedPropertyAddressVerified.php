<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\TrackedPropertyAddress;

/**
 * Fires when an agent first sets verified_at on a tracked_property_addresses
 * row (confidence transitions to 'verified'). Spec §14.1.
 */
final class TrackedPropertyAddressVerified extends AbstractDomainEvent
{
    public function __construct(
        public readonly TrackedPropertyAddress $address,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->address->agency_id; }
    public function actorUserId(): ?int { return $this->address->verified_by_user_id ? (int) $this->address->verified_by_user_id : null; }

    public function subject(): ?array
    {
        return [TrackedPropertyAddress::class, (int) $this->address->id];
    }

    public function context(): array
    {
        return [
            'tracked_property_id' => (int) $this->address->tracked_property_id,
            'verified_at'         => optional($this->address->verified_at)->toIso8601String(),
            'source_type'         => (string) $this->address->source_type,
        ];
    }
}
