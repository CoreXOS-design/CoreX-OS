<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;

/**
 * An e-sign template was exported as a package (.cxpkg). Fact only — nothing
 * subscribes yet; the audit row is written in template_transfer_log.
 * Spec: .ai/specs/esign-template-transfer.md §7.
 */
final class TemplatePackageExported extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $templateId,
        public readonly ?int $agencyIdValue,
        public readonly ?int $actorUserIdValue,
        public readonly string $packageChecksum,
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
        return ['docuperfect_templates', $this->templateId];
    }

    public function context(): array
    {
        return ['package_checksum' => $this->packageChecksum];
    }
}
