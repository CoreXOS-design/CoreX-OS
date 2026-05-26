<?php

declare(strict_types=1);

namespace App\Events\Document;

use App\Events\AbstractDomainEvent;
use App\Models\Document;

/**
 * Fires when a Document receives a signature. Note: by user exclusion, this
 * event is NOT fired from ESignWizardController / Docuperfect wizard code in
 * this wave — that wiring is deferred to a future wave. External signature
 * receipt paths (e.g. webhook callbacks) MAY fire this event.
 */
final class DocumentSigned extends AbstractDomainEvent
{
    /**
     * @param array<string,mixed> $signature
     */
    public function __construct(
        public readonly Document $document,
        public readonly array $signature,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->document->agency_id ?? null; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Document::class, $this->document->id]; }

    public function context(): array
    {
        return ['signature' => $this->signature];
    }
}
