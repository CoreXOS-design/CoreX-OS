<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;

/**
 * Fires when TrackedPropertyMatchOrCreateService creates a new TrackedProperty
 * because no existing record matched.
 */
final class TrackedPropertyCreated extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $trackedPropertyId,
        public readonly int $agencyId,
        public readonly string $sourceType,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }

    public function subject(): ?array
    {
        return ['App\\Models\\Prospecting\\TrackedProperty', $this->trackedPropertyId];
    }

    public function context(): array
    {
        return ['source_type' => $this->sourceType];
    }
}
