<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\Town;

/**
 * Fires when a Town is created, updated, or archived (soft-deleted) within an
 * agency's prospecting setup.
 *
 * Subscribers (Phase 1):
 *   - App\Listeners\Audit\RecordDomainEvent — writes to domain_event_log
 *   - App\Listeners\Prospecting\InvalidateProspectingConfigurationCache
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class TownConfigured extends AbstractDomainEvent
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_ARCHIVED = 'archived';

    public function __construct(
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
        return [Town::class, $this->town->id];
    }

    public function context(): array
    {
        return [
            'action'    => $this->action,
            'town_name' => $this->town->name,
            'town_slug' => $this->town->slug,
        ];
    }
}
