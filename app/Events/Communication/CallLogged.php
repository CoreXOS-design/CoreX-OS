<?php

declare(strict_types=1);

namespace App\Events\Communication;

use App\Events\AbstractDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires when an agent records an outbound call (typically the Build E.5
 * outbound-call log button). Spec §14.3.
 */
final class CallLogged extends AbstractDomainEvent
{
    public function __construct(
        public readonly Model $subjectModel,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $outcome,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $notes = null,
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
            'outcome'          => $this->outcome,
            'duration_seconds' => $this->durationSeconds,
            'has_notes'        => $this->notes !== null && $this->notes !== '',
        ];
    }
}
