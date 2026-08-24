<?php

declare(strict_types=1);

namespace App\Events\Assistant;

use App\Events\AbstractDomainEvent;
use App\Models\AssistantAssignment;

/**
 * AT-267 multi-agent addendum — a Sub-Agent was linked (or re-linked) to an assistant's
 * assignment. Informational only — not a consent gate (M3): the Sub-Agent is notified so they
 * know someone else's assistant can now reach their records, but linking itself needs no
 * approval from them.
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §8
 */
final class SubAgentLinked extends AbstractDomainEvent
{
    public function __construct(
        public readonly AssistantAssignment $assignment,
        public readonly int $subAgentUserId,
        public readonly ?int $addedByUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->assignment->agency_id; }
    public function actorUserId(): ?int { return $this->addedByUserId; }
    public function subject(): ?array { return [AssistantAssignment::class, $this->assignment->id]; }
}
