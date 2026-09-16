<?php

declare(strict_types=1);

namespace App\Events\Branch;

use App\Events\AbstractDomainEvent;
use App\Models\Branch;

/**
 * A branch was archived (soft-deleted) and every agent on it was moved to
 * another branch in the same transaction.
 *
 * Spec: .ai/specs/branch-archive-reassignment.md §8 (AT-420)
 * Catalogue: .ai/specs/corex-domain-events-spec.md §5
 *
 * Carries scalars plus the archived Branch (already soft-deleted when this
 * fires — read it with withTrashed()). Every agent move also fires its own
 * Agent\AgentBranchAssigned with reason 'branch_archived'; this event is the
 * branch-level fact for the audit log and for anything that must react to a
 * branch closing as a whole.
 */
final class BranchArchived extends AbstractDomainEvent
{
    /**
     * @param  int[]  $movedUserIds
     */
    public function __construct(
        public readonly Branch $branch,
        public readonly ?int $actorUserId = null,
        public readonly array $movedUserIds = [],
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->branch->agency_id ? (int) $this->branch->agency_id : null;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [Branch::class, $this->branch->id];
    }

    public function context(): array
    {
        return [
            'branch_name'    => $this->branch->name,
            'moved_user_ids' => $this->movedUserIds,
        ];
    }
}
