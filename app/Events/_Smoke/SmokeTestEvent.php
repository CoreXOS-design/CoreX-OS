<?php

declare(strict_types=1);

namespace App\Events\_Smoke;

use App\Events\AbstractDomainEvent;

/**
 * Used ONLY for verifying the domain events foundation. NOT a production
 * event. Delete this file once Build Prompt 02 of the events spec adds
 * the first real event class.
 *
 * Spec: .ai/specs/corex-domain-events-spec.md Section 8 row 01.
 */
class SmokeTestEvent extends AbstractDomainEvent
{
    public function __construct(
        public readonly string $message,
        public readonly ?int $agencyId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyId;
    }
}
