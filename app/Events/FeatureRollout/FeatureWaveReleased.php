<?php

declare(strict_types=1);

namespace App\Events\FeatureRollout;

use App\Events\AbstractDomainEvent;

/**
 * Fires when a wave of an agency's gradual feature rollout goes live (scheduled, "release now", or
 * "turn everything on"). Spec: .ai/specs/agency-feature-rollout.md §3.5, §3.6.
 *
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5 (AT-478 additions).
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 *
 * Subscribers: Audit\RecordDomainEvent (wildcard). The user release announcement (Part 3) reads
 * released waves directly.
 */
final class FeatureWaveReleased extends AbstractDomainEvent
{
    /**
     * @param list<string> $releasedFeatureKeys features switched on by this release
     * @param list<string> $awaitingAdminKeys   features that wait for the admin to switch them on
     */
    public function __construct(
        public readonly int $agencyId,
        public readonly int $waveId,
        public readonly array $releasedFeatureKeys,
        public readonly array $awaitingAdminKeys = [],
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
}
