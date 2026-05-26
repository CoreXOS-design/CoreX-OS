<?php

declare(strict_types=1);

namespace App\Events\Prospecting;

use App\Events\AbstractDomainEvent;

/**
 * Fires when an agency's Build E suggested-action thresholds row changes.
 *
 * Carries the agency, the actor (or null for system writes) and a per-field
 * diff so downstream listeners can react to the specific tuning change
 * without re-reading the table. Recorded in domain_event_log via the
 * existing RecordDomainEvent listener.
 *
 * Spec: .ai/specs/build-e-suggested-action-chips-spec.md §8.4.
 */
final class SuggestedActionThresholdsUpdated extends AbstractDomainEvent
{
    /**
     * @param array<string, array{old:mixed,new:mixed}> $diff
     */
    public function __construct(
        public readonly int $agencyId,
        public readonly ?int $actorUserId,
        public readonly array $diff,
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

    public function context(): array
    {
        return [
            'fields_changed' => array_keys($this->diff),
            'diff'           => $this->diff,
        ];
    }
}
