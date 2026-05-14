<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;
use App\Models\Prospecting\PropertyTypeOption;

/**
 * Fires when a PropertyTypeOption is created, updated, or archived.
 *
 * Subscribers (Phase 1):
 *   - App\Listeners\Audit\RecordDomainEvent — writes to domain_event_log
 *   - App\Listeners\Prospecting\InvalidateProspectingConfigurationCache
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class PropertyTypeConfigured extends AbstractDomainEvent
{
    public const ACTION_CREATED  = 'created';
    public const ACTION_UPDATED  = 'updated';
    public const ACTION_ARCHIVED = 'archived';

    public function __construct(
        public readonly PropertyTypeOption $propertyType,
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
        return [PropertyTypeOption::class, $this->propertyType->id];
    }

    public function context(): array
    {
        return [
            'action'              => $this->action,
            'property_type_name'  => $this->propertyType->name,
            'property_type_slug'  => $this->propertyType->slug,
            'is_active'           => $this->propertyType->is_active,
        ];
    }
}
