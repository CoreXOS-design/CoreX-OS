<?php

declare(strict_types=1);

namespace App\Events\Docuperfect;

use App\Events\AbstractDomainEvent;

/**
 * An e-sign template package was imported and a NEW agency-owned template was
 * created (agency = the target agency). Fired after the import transaction has
 * committed. Spec: .ai/specs/esign-template-transfer.md §7.
 */
final class TemplatePackageImported extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $templateId,
        public readonly int $targetAgencyId,
        public readonly ?int $actorUserIdValue,
        public readonly string $packageChecksum,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->targetAgencyId;
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
