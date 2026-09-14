<?php

declare(strict_types=1);

namespace App\Events\Compliance;

use App\Events\AbstractDomainEvent;
use App\Models\Compliance\WhistleblowComplaint;

/**
 * A compliance report (whistleblow complaint) was submitted for approval. Spec §9.2.
 */
final class WhistleblowReportSubmitted extends AbstractDomainEvent
{
    public function __construct(
        public readonly WhistleblowComplaint $complaint,
        public readonly ?int $submittedByUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->complaint->agency_id;
    }

    public function actorUserId(): ?int
    {
        return $this->submittedByUserId;
    }

    public function subject(): ?array
    {
        return [WhistleblowComplaint::class, $this->complaint->id];
    }

    public function context(): array
    {
        return ['tier' => $this->complaint->tier];
    }
}
