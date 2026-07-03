<?php

declare(strict_types=1);

namespace App\Events\Document;

use App\Events\AbstractDomainEvent;
use App\Models\Document;

/**
 * Fires when a misfiled document is re-filed to the correct party (AT-167).
 *
 * A contact-only document (e.g. an ID) that was wrongly anchored to the
 * property — or left unfiled — is moved onto the correct contact(s), and the
 * wrong property anchor is removed per the type's Save-to rule. Emitted after
 * the move commits; auto-recorded by the base-class audit listener
 * (RecordDomainEvent → domain_event_log). No hard delete ever occurs.
 */
final class DocumentRefiled extends AbstractDomainEvent
{
    /**
     * @param  int[]  $fromPropertyIds  property links removed by the refile
     * @param  int[]  $toContactIds     contact links added by the refile
     */
    public function __construct(
        public readonly Document $document,
        public readonly array $fromPropertyIds = [],
        public readonly array $toContactIds = [],
        public readonly bool $keptProperty = false,
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->document->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function subject(): ?array
    {
        return [Document::class, $this->document->id];
    }

    public function context(): array
    {
        return [
            'document_type_id'  => $this->document->document_type_id,
            'from_property_ids' => array_values($this->fromPropertyIds),
            'to_contact_ids'    => array_values($this->toContactIds),
            'kept_property'     => $this->keptProperty,
        ];
    }
}
