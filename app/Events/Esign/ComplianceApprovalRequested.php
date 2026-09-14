<?php

declare(strict_types=1);

namespace App\Events\Esign;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\EsignApproval;
use App\Models\Docuperfect\SignatureTemplate;

/**
 * An e-sign document was held at the compliance gate and needs an officer's approval before it
 * leaves the agency. Spec .ai/specs/esign-compliance-approval-gate.md §6.2.
 */
final class ComplianceApprovalRequested extends AbstractDomainEvent
{
    public function __construct(
        public readonly EsignApproval $approval,
        public readonly SignatureTemplate $template,
        public readonly ?int $requestedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->approval->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function subject(): ?array
    {
        return [SignatureTemplate::class, $this->template->id];
    }

    public function context(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'document_id' => $this->template->document_id,
        ];
    }
}
