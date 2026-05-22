<?php

declare(strict_types=1);

namespace App\Events\Compliance;

use App\Events\AbstractDomainEvent;

/**
 * Phase 9d — fires when a CO confirms an RCR submission (status → submitted,
 * snapshot taken, export generated). Slug: rcr_submission.submitted.
 *
 * Subject: the agency (the RCR is an agency-level artefact). Renders in the
 * agency's compliance activity feed.
 */
final class RcrSubmissionSubmitted extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $submissionId,
        public readonly ?int $agencyIdValue,
        public readonly ?int $actorUserIdValue,
        public readonly string $directiveReference,
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
        return ['App\\Models\\Agency', (int) $this->agencyIdValue];
    }

    public function context(): array
    {
        return [
            'submission_id'        => $this->submissionId,
            'directive_reference'  => $this->directiveReference,
        ];
    }
}
