<?php

declare(strict_types=1);

namespace App\Events\Agent;

use App\Events\AbstractDomainEvent;
use App\Models\User;

/**
 * Fires when an admin toggles a user's "Principal Property Practitioner"
 * flag on/off. .ai/specs/ppra-inspection-pack.md §6.6a — audit trail for
 * the PPRA Inspection Pack's item (c) principal roster.
 */
final class AgentPrincipalPractitionerFlagChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly User $user,
        public readonly bool $toFlag,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->user->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [User::class, $this->user->id]; }

    public function context(): array
    {
        return ['from' => ! $this->toFlag, 'to' => $this->toFlag];
    }
}
