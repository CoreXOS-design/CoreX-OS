<?php

declare(strict_types=1);

namespace App\Events\Agent;

use App\Events\AbstractDomainEvent;
use App\Models\Branch;
use App\Models\User;

/**
 * Fires when an agent is assigned (or reassigned) to a branch.
 *
 * `reason` says why the move happened:
 *   - 'manual'          — an admin changed the agent's branch on the user screen
 *   - 'branch_archived' — the agent's branch was archived and the wizard moved
 *                         them (spec: branch-archive-reassignment.md §8, AT-420)
 * `fromBranchId` is the branch they left (null when they had none). The dated
 * row in user_branch_history is written by UserObserver, not by listeners here.
 */
final class AgentBranchAssigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly User $user,
        public readonly Branch $branch,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
        public readonly string $reason = 'manual',
        public readonly ?int $fromBranchId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->user->agency_id ?? $this->branch->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [User::class, $this->user->id]; }

    public function context(): array
    {
        return [
            'branch_id'      => $this->branch->id,
            'from_branch_id' => $this->fromBranchId,
            'reason'         => $this->reason,
        ];
    }
}
