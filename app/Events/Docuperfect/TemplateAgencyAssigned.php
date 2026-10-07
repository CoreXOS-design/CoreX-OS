<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\Template;

/**
 * An e-sign template that had NO owning agency was given one (php artisan templates:assign-agency).
 * The audit listener (RecordDomainEvent) writes the row to domain_event_log, so the repair is on record
 * with who/what/when. Spec: .ai/specs/leases.md §15.12.4 (Build L0).
 */
final class TemplateAgencyAssigned extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $templateId,
        public readonly string $templateName,
        public readonly ?int $previousAgencyId,
        public readonly int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->agencyId; }
    public function subject(): ?array { return [Template::class, $this->templateId]; }
}
