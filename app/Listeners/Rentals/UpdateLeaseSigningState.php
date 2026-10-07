<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\AbstractDomainEvent;
use App\Events\Docuperfect\SignatureEnvelopeCancelled;
use App\Events\Docuperfect\SignatureEnvelopeDeclined;
use App\Events\Docuperfect\SignatureEnvelopeExpired;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Events\Docuperfect\SignatureEnvelopeSent;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Services\Rentals\LeaseSigningStateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/leases.md §15.15 (Build L3b). The one listener that keeps a lease's `signing_status` in step with its
 * e-sign envelope. The e-sign engine announces five things — an envelope was sent, finalised (signed, approved and
 * filed), declined, cancelled or expired — and for each one this finds the lease whose agreement it is (by
 * `leases.signature_template_id`) and hands it to LeaseSigningStateService, which is idempotent.
 *
 * A document that did not come from a lease has no lease to find, so nothing happens. A fault here is logged and
 * swallowed: it must never disturb a signing that has legally completed. The safety net (Lease::reconcileSigning(),
 * the nightly leases:reconcile-signing) repairs anything this misses.
 *
 * Synchronous on purpose — domain events carry readonly state.
 */
class UpdateLeaseSigningState
{
    public function __construct(private readonly LeaseSigningStateService $state) {}

    public function handle(AbstractDomainEvent $event): void
    {
        try {
            if (! $event instanceof SignatureEnvelopeSent
                && ! $event instanceof SignatureEnvelopeFinalized
                && ! $event instanceof SignatureEnvelopeDeclined
                && ! $event instanceof SignatureEnvelopeCancelled
                && ! $event instanceof SignatureEnvelopeExpired) {
                return;
            }

            // Most envelopes are mandates, addenda and the like: one indexed look-up, then out.
            if (! Lease::withoutGlobalScopes()->where('signature_template_id', $event->signatureTemplateId)->exists()) {
                return;
            }

            $envelope = SignatureTemplate::withoutGlobalScopes()->find($event->signatureTemplateId);
            if (! $envelope) {
                return;
            }

            // Whoever cancels is the signed-in user; the other four are not acts of a person at this point.
            $actorUserId = $event instanceof SignatureEnvelopeCancelled && Auth::check() ? (int) Auth::id() : null;

            $this->state->applyEnvelope($envelope, $event->reason, $actorUserId, $event instanceof SignatureEnvelopeSent);
        } catch (\Throwable $e) {
            Log::error('Lease signing: UpdateLeaseSigningState failed (the signing itself is unaffected)', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
