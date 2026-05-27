<?php

declare(strict_types=1);

namespace App\Events\Document;

use App\Events\AbstractDomainEvent;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a Document is uploaded / created. The owner is the polymorphic
 * subject the document belongs to (Contact, Property, Deal, etc.).
 */
final class DocumentUploaded extends AbstractDomainEvent
{
    public function __construct(
        public readonly Document $document,
        public readonly ?Model $owner = null,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->document->agency_id
            ?? ($this->owner->agency_id ?? null);
    }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [Document::class, $this->document->id]; }

    public function context(): array
    {
        return [
            'owner_id' => $this->owner?->getKey(),
            'owner_class' => $this->owner ? get_class($this->owner) : null,
        ];
    }
}
