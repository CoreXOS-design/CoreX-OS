<?php

declare(strict_types=1);

namespace App\Events\Assistant;

use App\Events\AbstractDomainEvent;
use App\Models\AssistantAssignment;

/**
 * AT-267 multi-agent addendum — a Sub-Agent link was removed from an assistant's assignment.
 * The link row itself is soft-deleted (restorable); this event is the audit + notification hook.
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §8
 */
final class SubAgentUnlinked extends AbstractDomainEvent
{
    public function __construct(
        public readonly AssistantAssignment $assignment,
        public readonly int $subAgentUserId,
        public readonly ?int $removedByUserId,
        public readonly ?string $reason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->assignment->agency_id; }
    public function actorUserId(): ?int { return $this->removedByUserId; }
    public function subject(): ?array { return [AssistantAssignment::class, $this->assignment->id]; }
}
