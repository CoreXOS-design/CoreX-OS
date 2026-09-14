<?php

declare(strict_types=1);

namespace App\Events\Esign;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\EsignApproval;
use App\Models\Docuperfect\SignatureTemplate;

/**
 * An officer approved, declined or overrode a held e-sign document. Spec §6.3.
 */
final class ComplianceApprovalDecided extends AbstractDomainEvent
{
    public function __construct(
        public readonly EsignApproval $approval,
        public readonly SignatureTemplate $template,
        public readonly ?int $decidedByUserId = null,
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
        return $this->decidedByUserId;
    }

    public function subject(): ?array
    {
        return [SignatureTemplate::class, $this->template->id];
    }

    public function context(): array
    {
        return [
            'approval_id' => $this->approval->id,
            'decision'    => $this->approval->status,
            'is_override' => (bool) $this->approval->is_override,
        ];
    }
}
