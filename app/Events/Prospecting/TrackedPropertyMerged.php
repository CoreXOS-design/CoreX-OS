<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\TrackedProperty;

/**
 * Fires when a tracked_property is flagged as a duplicate of another and merged
 * (`status = duplicate`, `duplicate_of_tracked_property_id` set). The surviving
 * TP receives the merged TP's external_refs + addresses + market_data_points.
 *
 * Spec §14.1.
 */
final class TrackedPropertyMerged extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $survivingTrackedPropertyId,
        public readonly int $mergedTrackedPropertyId,
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
        return [TrackedProperty::class, $this->survivingTrackedPropertyId];
    }

    public function context(): array
    {
        return [
            'merged_tracked_property_id' => $this->mergedTrackedPropertyId,
        ];
    }
}
