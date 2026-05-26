<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\PriceBand;

/**
 * Fires when a PriceBand is created, updated, or archived.
 *
 * Subscribers (Phase 1):
 *   - App\Listeners\Audit\RecordDomainEvent — writes to domain_event_log
 *   - App\Listeners\Prospecting\InvalidateProspectingConfigurationCache
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class PriceBandConfigured extends AbstractDomainEvent
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_ARCHIVED = 'archived';

    public function __construct(
        public readonly PriceBand $band,
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
        return [PriceBand::class, $this->band->id];
    }

    public function context(): array
    {
        return [
            'action'        => $this->action,
            'listing_type'  => $this->band->listing_type,
            'band_name'     => $this->band->name,
            'price_min'     => $this->band->price_min,
            'price_max'     => $this->band->price_max,
        ];
    }
}
