<?php

declare(strict_types=1);

namespace App\Events\FeatureRollout;

use App\Events\AbstractDomainEvent;

/**
 * Something about an agency's gradual feature rollout was changed or decided — started, paused, a
 * feature skipped or moved, the pace changed, who-gets-access reviewed, and so on (AT-478 §12.10).
 * Releases are recorded by FeatureWaveReleased. Together they are the rollout's history: the wildcard
 * Audit\RecordDomainEvent writes each to domain_event_log, and FeatureRolloutHistory reads them back.
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-478). Payload is SCALARS ONLY.
 */
final class FeatureRolloutChanged extends AbstractDomainEvent
{
    /**
     * @param string               $action  see FeatureRolloutHistory::ACTIONS
     * @param array<string, mixed> $details scalars / lists of scalars (wave title, feature keys, …)
     */
    public function __construct(
        public readonly int $agencyId,
        public readonly string $action,
        public readonly array $details = [],
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
}
