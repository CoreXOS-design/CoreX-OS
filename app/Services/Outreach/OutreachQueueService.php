<?php

namespace App\Services\Outreach;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Outreach\OutreachQueue;
use App\Models\Property;
use App\Models\User;
use App\Services\SellerOutreach\MarketingConsentService;
use Carbon\Carbon;

/**
 * AT-117 §7 — the ONE canonical "add to the outreach queue" path. Every source
 * (composer, MIC, map) routes its queue WRITE through here so the consent gate
 * (canMarketTo, §4b), the send-window constraint on due_at (§4a) and the
 * required body capture happen in exactly one place — no parallel write logic.
 *
 * The body MUST be supplied by the caller and is stored verbatim as
 * body_snapshot: the MIC/map paths persist no message text today (spec §3), so
 * the queue carries the prepared body. Opt-out/tracking tokens are left as the
 * caller passes them (literal) and resolve fresh at dispatch (§4b).
 */
class OutreachQueueService
{
    public function __construct(
        private OutreachWindowService $window,
        private MarketingConsentService $consent,
    ) {}

    /**
     * Validate (consent + window + non-empty body) and create the queue row.
     *
     * @return array{ok:bool, status:int, message:string, row?:OutreachQueue, extra?:array}
     */
    public function enqueue(
        Agency $agency,
        Contact $contact,
        User $agent,
        string $channel,
        string $source,
        string $body,
        Carbon $dueAt,
        ?Property $property = null
    ): array {
        // Consent at queue time (§4b) — don't even queue an already-blocked contact.
        if (!$this->consent->canMarketTo($contact, $channel)) {
            $reason = $this->consent->marketingBlockReason($contact, $channel) ?? 'not_marketable';
            return ['ok' => false, 'status' => 422, 'message' => 'Cannot queue: this contact is not marketable right now (' . $reason . ').'];
        }

        // due_at must fall inside the agency send-window (§4a).
        if (!$this->window->isSendAllowed($agency, $dueAt->copy())) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'That time is outside the outreach send-window. ' . $this->window->blockedMessage($agency, $dueAt->copy()),
                'extra' => ['next_opens_at' => optional($this->window->nextOpensAt($agency, $dueAt->copy()))->toIso8601String()],
            ];
        }

        // body_snapshot is REQUIRED — these paths persist nothing else.
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Cannot queue: the message is empty.'];
        }

        $row = OutreachQueue::create([
            'agency_id'     => $agency->id,
            'contact_id'    => $contact->id,
            'property_id'   => $property?->id,
            'agent_id'      => $agent->id,
            'channel'       => $channel,
            'source'        => $source,
            'body_snapshot' => $body,
            'due_at'        => $dueAt,
            // status defaults to pending via the model.
        ]);

        return [
            'ok'      => true,
            'status'  => 200,
            'row'     => $row,
            'message' => 'Queued — will surface in the outreach queue at ' . $dueAt->format('D j M, H:i') . '.',
        ];
    }
}
