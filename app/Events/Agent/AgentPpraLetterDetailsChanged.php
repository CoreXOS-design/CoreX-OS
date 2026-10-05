<?php

declare(strict_types=1);

namespace App\Events\Agent;

use App\Events\AbstractDomainEvent;
use App\Models\User;

/**
 * Fires when an admin changes a staff member's "Full first names (as on ID)" or "PPRA category" —
 * the two fields the PPRA Confirmation of Employment letter prints.
 * .ai/specs/ppra-ffc-employment-letter.md §18 — audit trail via the domain-event logger.
 */
final class AgentPpraLetterDetailsChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly User $user,
        public readonly string $field,
        public readonly ?string $from,
        public readonly ?string $to,
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
        return ['field' => $this->field, 'from' => $this->from, 'to' => $this->to];
    }
}
