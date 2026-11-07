<?php

declare(strict_types=1);

namespace App\Events\SoloAgent;

use App\Events\AbstractDomainEvent;
use App\Models\SoloAgentSignup;

/**
 * A suspended single-agent account was reopened.
 *
 * Spec: .ai/specs/solo-agent-accounts.md; catalogue: .ai/specs/corex-domain-events-spec.md section 5.
 * Payload is SCALARS ONLY; keep listeners SYNC (see AgencyFeatureToggled).
 */
final class SoloAgentUnsuspended extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $signupId,
        public readonly int $agencyId,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [SoloAgentSignup::class, $this->signupId]; }
}
