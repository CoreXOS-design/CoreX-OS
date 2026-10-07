<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\AbstractDomainEvent;

/**
 * .ai/specs/leases.md §15.15 (Build L1 — INERT). The one listener that keeps a lease's `signing_status`
 * in step with its e-sign envelope: it will handle SignatureEnvelopeSent / Finalized / Declined /
 * Cancelled / Expired, idempotently and scoped by `leases.signature_template_id`, and call
 * LeaseAgreementCheck::verdict() at completion. L1 registers it so the wiring exists, but it does
 * nothing: no engine code emits those events yet (Build L3b), and a lease's state is changed by nothing
 * but what already changes it today.
 *
 * Synchronous on purpose — domain events carry readonly state.
 */
class UpdateLeaseSigningState
{
    public function handle(AbstractDomainEvent $event): void
    {
        // Inert until Build L3b.
    }
}
