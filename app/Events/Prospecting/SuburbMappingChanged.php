<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\Town;
use App\Models\Prospecting\TownSuburb;

/**
 * Fires when a suburb-to-town mapping is created, updated, or archived.
 *
 * Subscribers (Phase 1):
 *   - App\Listeners\Audit\RecordDomainEvent — writes to domain_event_log
 *   - App\Listeners\Prospecting\InvalidateProspectingConfigurationCache
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class SuburbMappingChanged extends AbstractDomainEvent
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_ARCHIVED = 'archived';

    public function __construct(
        public readonly TownSuburb $suburb,
        public readonly Town $town,
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
        return [TownSuburb::class, $this->suburb->id];
    }

    public function context(): array
    {
        return [
            'action'             => $this->action,
            'town_name'          => $this->town->name,
            'suburb_name'        => $this->suburb->suburb_name,
            'suburb_normalised'  => $this->suburb->suburb_normalised,
        ];
    }
}
