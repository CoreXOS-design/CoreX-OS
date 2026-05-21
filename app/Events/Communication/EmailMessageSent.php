<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when an email is actually sent. Spec §14.3.
 */
final class EmailMessageSent extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $subjectModel,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $recipientEmail,
        public readonly ?string $templateKey = null,
        public readonly ?string $subjectLine = null,
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
            'recipient_email' => $this->recipientEmail,
            'template_key'    => $this->templateKey,
            'subject_line'    => $this->subjectLine ? mb_substr($this->subjectLine, 0, 200) : null,
        ];
    }
}
