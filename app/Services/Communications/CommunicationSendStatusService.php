<?php

namespace App\Services\Communications;

use App\Events\Communication\CommunicationMarkedNotDelivered;
use App\Events\Communication\CommunicationResent;
use App\Events\Communication\CommunicationSendStatusReverted;
use App\Models\Communications\Communication;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Contact-details Phase 4 — THE canonical write path for a communication's
 * send_status. Every transition here does the SAME three things, no matter
 * which UI triggers it:
 *   1. Write the status change (send_status/send_status_set_by_user_id/
 *      send_status_set_at) — the row itself is otherwise NEVER modified.
 *   2. Recompute the contact's last_contacted_at from scratch (Contact::
 *      recomputeLastContacted()) — a failed send, even the contact's ONLY
 *      send ever, must not leave them marked as "communicated".
 *   3. Dispatch a past-tense domain event — auto-audited to domain_event_log,
 *      giving the "created → flagged → reselected → resent" chain a real,
 *      queryable trail with who/when at every step.
 */
class CommunicationSendStatusService
{
    public function __construct(private readonly OutboundProvisionalLogger $logger)
    {
    }

    /**
     * Flag a send as "could not send / not delivered". Idempotent — flagging
     * an already-flagged row just re-stamps actor/time (no duplicate event
     * chain confusion; the domain_event_log still gets a fresh entry so a
     * re-flag with a different reason is itself on record).
     */
    public function markNotDelivered(Communication $communication, Contact $contact, ?int $actorUserId, ?string $reason = null): Communication
    {
        DB::transaction(function () use ($communication, $contact, $actorUserId, $reason) {
            $communication->forceFill([
                'send_status' => Communication::SEND_STATUS_NOT_DELIVERED,
                'send_status_set_by_user_id' => $actorUserId,
                'send_status_set_at' => now(),
            ])->save();

            $contact->recomputeLastContacted();
        });

        event(new CommunicationMarkedNotDelivered(
            contact: $contact,
            communicationId: (int) $communication->id,
            channel: $communication->channel,
            actorUserId: $actorUserId,
            agencyId: (int) $communication->agency_id,
            reason: $reason,
        ));

        return $communication->refresh();
    }

    /**
     * Undo a not_delivered flag — the agent flagged it by mistake, or later
     * confirmed the contact DID receive it. Puts the row back to sent and
     * recomputes last_contacted_at (which may re-advance to include this send
     * again, exactly as if it had never been flagged).
     */
    public function revertToSent(Communication $communication, Contact $contact, ?int $actorUserId): Communication
    {
        DB::transaction(function () use ($communication, $contact, $actorUserId) {
            $communication->forceFill([
                'send_status' => Communication::SEND_STATUS_SENT,
                'send_status_set_by_user_id' => $actorUserId,
                'send_status_set_at' => now(),
            ])->save();

            $contact->recomputeLastContacted();
        });

        event(new CommunicationSendStatusReverted(
            contact: $contact,
            communicationId: (int) $communication->id,
            channel: $communication->channel,
            actorUserId: $actorUserId,
            agencyId: (int) $communication->agency_id,
        ));

        return $communication->refresh();
    }

    /**
     * Reselect a different phone/email and resend, after the original was
     * flagged not_delivered. Creates a NEW communications row (via the same
     * OutboundProvisionalLogger every other send goes through) linked back to
     * the original — the original is never touched again. The new row starts
     * send_status=sent, so recomputeLastContacted() naturally picks it up.
     *
     * @param string $recipientValue the reselected phone number / email address
     */
    public function resend(
        Communication $original,
        Contact $contact,
        string $recipientValue,
        ?string $subject,
        ?string $body,
        ?int $actorUserId
    ): Communication {
        $new = $this->logger->log(
            $contact,
            $original->channel,
            $subject,
            $body,
            $actorUserId,
            resentFromCommunicationId: (int) $original->id,
            recipientValue: $recipientValue,
        );

        event(new CommunicationResent(
            contact: $contact,
            originalCommunicationId: (int) $original->id,
            newCommunicationId: (int) $new->id,
            channel: $original->channel,
            actorUserId: $actorUserId,
            agencyId: (int) $original->agency_id,
        ));

        return $new;
    }
}
