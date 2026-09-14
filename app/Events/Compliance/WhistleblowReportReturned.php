<?php

declare(strict_types=1);

namespace App\Events\Compliance;

use App\Events\AbstractDomainEvent;
use App\Models\Compliance\WhistleblowComplaint;

/**
 * An approver sent a compliance report back to its filer — changes requested, or rejected.
 * Spec §9.2. `$outcome` is 'changes_requested' | 'rejected'.
 */
final class WhistleblowReportReturned extends AbstractDomainEvent
{
    public function __construct(
        public readonly WhistleblowComplaint $complaint,
        public readonly string $outcome,
        public readonly string $notes,
        public readonly ?int $returnedByUserId = null,
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
        return $this->returnedByUserId;
    }

    public function subject(): ?array
    {
        return [WhistleblowComplaint::class, $this->complaint->id];
    }

    public function context(): array
    {
        return ['outcome' => $this->outcome];
    }
}
