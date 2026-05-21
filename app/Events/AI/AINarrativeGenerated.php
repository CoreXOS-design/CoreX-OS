<?php

declare(strict_types=1);

namespace App\Events\AI;

use App\Events\AbstractDomainEvent;
use App\Models\AI\AINarrativeCache;

/**
 * Fires when an AI narrative is generated (and cached). Drives the AI cost
 * dashboard + accuracy metrics.
 *
 * Spec §14.5.
 */
final class AINarrativeGenerated extends AbstractDomainEvent
{
    public function __construct(
        public readonly AINarrativeCache $narrative,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->narrative->agency_id !== null ? (int) $this->narrative->agency_id : null; }
    public function actorUserId(): ?int { return null; /* system-triggered */ }

    public function subject(): ?array
    {
        return [AINarrativeCache::class, (int) $this->narrative->id];
    }

    public function context(): array
    {
        return [
            'narrative_type' => (string) $this->narrative->narrative_type,
            'cache_key'      => (string) $this->narrative->cache_key,
            'model'          => (string) $this->narrative->model,
            'prompt_version' => (string) $this->narrative->prompt_version,
            'input_tokens'   => (int) $this->narrative->input_tokens,
            'output_tokens'  => (int) $this->narrative->output_tokens,
            'cost_zar'       => (float) $this->narrative->cost_zar,
        ];
    }
}
