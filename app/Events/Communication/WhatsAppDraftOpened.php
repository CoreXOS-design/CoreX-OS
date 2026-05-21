<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when an agent opens a WhatsApp draft against a subject (typically
 * a ProspectingListing or Contact). Doesn't imply sent — see
 * WhatsAppMessageSent for that.
 *
 * Spec §14.3.
 */
final class WhatsAppDraftOpened extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $subjectModel,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly ?string $templateKey = null,
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
        return ['template_key' => $this->templateKey];
    }
}
