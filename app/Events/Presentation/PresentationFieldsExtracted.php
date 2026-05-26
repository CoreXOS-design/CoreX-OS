<?php

declare(strict_types=1);

namespace App\Events\Presentation;

use App\Events\AbstractDomainEvent;

/**
 * Fires after presentation_fields are written for a presentation.
 *
 * Subscribers: PropagateCmaToProperty — back-propagates CMA-extracted fields to the
 * Property pillar. See .ai/specs/market-intelligence-discovery.md Section 13.4 for context.
 *
 * Domain-events catalogue: this event was introduced alongside the CMA back-propagation
 * listener. It is fired once per presentation, after the extraction foreach loop in
 * UploadExtractionService::propagateFields() completes.
 */
final class PresentationFieldsExtracted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $presentationId,
        public readonly ?int $agencyId,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return ['App\\Models\\Presentation', $this->presentationId];
    }

    public function context(): array
    {
        return ['presentation_id' => $this->presentationId];
    }
}
