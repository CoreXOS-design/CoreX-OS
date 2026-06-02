<?php

declare(strict_types=1);

namespace App\Events\Website;

use App\Events\AbstractDomainEvent;
use App\Models\User;

/**
 * An agent's website presence changed and agency websites should be notified.
 *
 *   published → show_on_website turned on (or a visible agent created)
 *   updated   → a visible agent's public profile changed
 *   removed   → show_on_website turned off, or the agent was soft-deleted
 *
 * Agents are agency-wide (not per-property), so the listener fans out to every
 * website key of the agent's agency. Spec: .ai/specs/agency-public-api.md §6.1.
 */
class AgentVisibilityChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly User $agent,
        public readonly string $action,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agent->agency_id ? (int) $this->agent->agency_id : null;
    }

    public function subject(): ?array
    {
        return [User::class, $this->agent->id];
    }

    public function webhookEvent(): string
    {
        return match ($this->action) {
            'published' => 'agent.published',
            'removed'   => 'agent.removed',
            default     => 'agent.updated',
        };
    }
}
