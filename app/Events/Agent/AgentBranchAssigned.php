<?php

declare(strict_types=1);

namespace App\Events\Agent;

use App\Events\AbstractDomainEvent;
use App\Models\Branch;
use App\Models\User;

/**
 * Fires when an agent is assigned (or reassigned) to a branch.
 */
final class AgentBranchAssigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly User $user,
        public readonly Branch $branch,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->user->agency_id ?? $this->branch->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [User::class, $this->user->id]; }

    public function context(): array
    {
        return ['branch_id' => $this->branch->id];
    }
}
