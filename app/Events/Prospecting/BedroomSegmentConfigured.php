<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\BedroomSegment;

/**
 * Fires when a BedroomSegment is created, updated, or archived.
 *
 * Subscribers (Phase 1):
 *   - App\Listeners\Audit\RecordDomainEvent — writes to domain_event_log
 *   - App\Listeners\Prospecting\InvalidateProspectingConfigurationCache
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class BedroomSegmentConfigured extends AbstractDomainEvent
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_ARCHIVED = 'archived';

    public function __construct(
        public readonly BedroomSegment $segment,
        public readonly string $action,
        public readonly ?int $actorUserId,
        public readonly int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [BedroomSegment::class, $this->segment->id];
    }

    public function context(): array
    {
        return [
            'action'        => $this->action,
            'segment_name'  => $this->segment->name,
            'beds_min'      => $this->segment->beds_min,
            'beds_max'      => $this->segment->beds_max,
        ];
    }
}
