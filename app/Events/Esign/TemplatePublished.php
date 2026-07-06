<?php

declare(strict_types=1);

namespace App\Events\Esign;

use App\Events\AbstractDomainEvent;
use App\Models\Docuperfect\CompiledTemplate;

/**
 * AT-177 / §11 — emitted when a CompiledTemplate version is published (frozen, hashed).
 *
 * The integration moat subscribes here: auto-file, FICA, and deal-pipeline listeners react
 * to a newly available signable artifact. Auto-audited via the AbstractDomainEvent base
 * listener (domain_event_log).
 */
final class TemplatePublished extends AbstractDomainEvent
{
    public function __construct(
        public readonly CompiledTemplate $compiledTemplate,
        public readonly ?int $publishedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->compiledTemplate->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->publishedByUserId;
    }

    public function subject(): ?array
    {
        return [CompiledTemplate::class, $this->compiledTemplate->id];
    }

    public function context(): array
    {
        return [
            'family' => $this->compiledTemplate->family,
            'version' => $this->compiledTemplate->version,
            'content_hash' => $this->compiledTemplate->content_hash,
        ];
    }
}
