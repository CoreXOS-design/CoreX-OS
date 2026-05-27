<?php

declare(strict_types=1);

namespace App\Events\Document;

use App\Events\AbstractDomainEvent;
use App\Models\Document;

/**
 * Fires when a Document is archived (soft-deleted per CLAUDE.md Non-Negotiable #1).
 */
final class DocumentArchived extends AbstractDomainEvent
{
    public function __construct(
        public readonly Document $document,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->document->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Document::class, $this->document->id]; }
}
