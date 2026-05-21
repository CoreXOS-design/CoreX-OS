<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when a WhatsApp message is actually sent (delivery handoff, not a
 * draft open). Spec §14.3.
 */
final class WhatsAppMessageSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $subjectModel,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $recipientPhone,
        public readonly ?string $templateKey = null,
        public readonly ?string $messagePreview = null,
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
            'recipient_phone' => $this->recipientPhone,
            'template_key'    => $this->templateKey,
            'message_preview' => $this->messagePreview ? mb_substr($this->messagePreview, 0, 200) : null,
        ];
    }
}
