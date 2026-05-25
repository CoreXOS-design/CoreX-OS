<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase A.2 — agent fired the WhatsApp composer from the map. Subject is
 * the model whose contact details seeded the WhatsApp link — most often a
 * Property (composer picks the recipient) or a Contact (direct send).
 *
 * event_type: `map_whats_app.launched` — LogAgentActivity rewrites
 * `whats_app` to `whatsapp`, so the persisted slug is `map_whatsapp.launched`.
 */
final class MapWhatsAppLaunched extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $subjectModel,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        public readonly ?int $propertyId = null,
        public readonly ?int $contactId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        return [get_class($this->subjectModel), (int) $this->subjectModel->getKey()];
    }

    public function context(): array
    {
        return [
            'property_id'  => $this->propertyId,
            'contact_id'   => $this->contactId,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
