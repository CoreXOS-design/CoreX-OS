<?php

declare(strict_types=1);

namespace App\Events\DealV2;

use App\Events\AbstractDomainEvent;
use App\Models\DealV2\DealStepInstance;

/**
 * AT-158 DR2 · WS4 — a DR2 pipeline step was completed with a POSITIVE outcome
 * (the stage "ticked"). This is the cross-pillar reactivity seam (non-negotiable
 * #9): the distribution engine subscribes to auto-send stage documents, and the
 * event is audited automatically via RecordDomainEvent. Emitted post-commit so
 * listeners never run inside the completion transaction.
 */
final class DealStepCompleted extends AbstractDomainEvent
{
    public function __construct(
        public readonly DealStepInstance $step,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->step->agency_id ?? null;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [DealStepInstance::class, $this->step->id];
    }

    public function context(): array
    {
        return [
            'deal_id'          => $this->step->deal_id,
            'pipeline_step_id' => $this->step->pipeline_step_id,
            'step_name'        => $this->step->name,
        ];
    }
}
