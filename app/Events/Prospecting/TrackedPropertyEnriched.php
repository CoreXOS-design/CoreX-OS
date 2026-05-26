<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;

/**
 * Fires when TrackedPropertyMatchOrCreateService matches an existing TrackedProperty
 * and applies new facts to it. The fields_added array lists columns that
 * actually changed; an empty array means a source_chain append with no column delta.
 */
final class TrackedPropertyEnriched extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $trackedPropertyId,
        public readonly int $agencyId,
        public readonly string $sourceType,
        public readonly array $fieldsAdded,
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
        return [
            'source_type'  => $this->sourceType,
            'fields_added' => $this->fieldsAdded,
        ];
    }
}
