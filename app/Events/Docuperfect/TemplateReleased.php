<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;

/**
 * A finished template was released to an agency and now waits for its authorisers (agency = the target agency). Fact only; the release log (docuperfect_template_release_events) is the audit record.
 * Spec: .ai/specs/esign-template-transfer.md §13 / corex-domain-events-spec.md.
 */
final class TemplateReleased extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $releaseId,
        public readonly int $templateId,
        public readonly int $agencyIdValue,
        public readonly ?int $actorUserIdValue,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdValue;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserIdValue;
    }

    public function subject(): ?array
    {
        return ['docuperfect_template_releases', $this->releaseId];
    }

    public function context(): array
    {
        return ['template_id' => $this->templateId];
    }
}
