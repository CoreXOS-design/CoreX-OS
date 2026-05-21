<?php

declare(strict_types=1);

namespace App\Events\AI;

use App\Events\AbstractDomainEvent;

/**
 * Fires when the AI gateway falls back from a failed primary call (rate
 * limit, timeout, model unavailable) to a templated fallback narrative.
 * Drives the SLO dashboard so the AI surface's reliability is visible.
 *
 * Spec §14.5.
 */
final class AINarrativeFailedFallback extends AbstractDomainEvent
{
    public function __construct(
        public readonly ?int $agencyIdValue,
        public readonly string $narrativeType,
        public readonly string $cacheKey,
        public readonly string $model,
        public readonly string $failureReason,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyIdValue; }
    public function actorUserId(): ?int { return null; /* system-triggered */ }

    /**
     * No persistent subject — the cache row hasn't been written (that's what
     * "failed" means). The cache_key is the closest stable identifier; passed
     * via context.
     */
    public function subject(): ?array { return null; }

    public function context(): array
    {
        return [
            'narrative_type' => $this->narrativeType,
            'cache_key'      => $this->cacheKey,
            'model'          => $this->model,
            'failure_reason' => mb_substr($this->failureReason, 0, 500),
        ];
    }
}
