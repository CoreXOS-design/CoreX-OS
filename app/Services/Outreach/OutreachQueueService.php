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
 * AT-117 — the ONE canonical "add to the outreach queue" path. Every source
 * (composer, MIC) routes its queue WRITE through here so the consent gate
 * (canMarketTo, §4b), reachability, cap and required body capture happen in
 * exactly one place — no parallel write logic. No due-time: rows are created
 * READY; the send-window gates DISPATCH only (not enqueue).
 *
 * The body MUST be supplied by the caller and is stored verbatim as
 * body_snapshot: the MIC paths persist no message text today (spec §3), so the
 * queue carries the prepared body. Opt-out/tracking tokens are left as the
 * caller passes them (literal) and resolve fresh at dispatch (§4b).
 */
class OutreachQueueService
{
    public function __construct(
        private MarketingConsentService $consent,
    ) {}

    /**
     * Validate (consent + reachability + non-empty body + cap) and create a READY
     * queue row. No due-time: the row is immediately ready; the ONLY send gate is
     * the agency send-window, enforced at DISPATCH (not here).
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
        ?Property $property = null
    ): array {
        // Consent at queue time (§4b) — don't even queue an already-blocked contact.
        // (Re-checked again at dispatch; a later opt-out drops the ready row.)
        if (!$this->consent->canMarketTo($contact, $channel)) {
            $reason = $this->consent->marketingBlockReason($contact, $channel) ?? 'not_marketable';
            return ['ok' => false, 'status' => 422, 'message' => 'Cannot queue: this contact is not marketable right now (' . $reason . ').'];
        }

        // body_snapshot is REQUIRED — these paths persist nothing else.
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Cannot queue: the message is empty.'];
        }

        // §9 — reachability: never queue what can never be dispatched on this channel
        // (closes the gap for the MIC/map endpoint, which doesn't run the composer's
        // validationIssues). The composer path also blocks this upstream.
        $reachable = match ($channel) {
            'whatsapp' => filled($contact->phone),
            'email'    => filled($contact->email),
            default    => true,
        };
        if (!$reachable) {
            $what = $channel === 'whatsapp' ? 'WhatsApp number' : 'email address';
            return ['ok' => false, 'status' => 422, 'message' => "Cannot queue: this contact has no {$what}."];
        }

        // §8 — optional per-agent daily cap (volume guard). NULL = no cap.
        $cap = $agency->outreach_queue_daily_cap_per_agent;
        if ($cap && $cap > 0) {
            $startOfDay = Carbon::now($agency->outreachTimezone())->startOfDay();
            $todayCount = OutreachQueue::where('agency_id', $agency->id)
                ->where('agent_id', $agent->id)
                ->where('created_at', '>=', $startOfDay)
                ->count();
            if ($todayCount >= $cap) {
                return ['ok' => false, 'status' => 422, 'message' => "Daily outreach-queue limit reached ({$cap} per agent). Send now, or queue more tomorrow."];
            }
        }

        $row = OutreachQueue::create([
            'agency_id'     => $agency->id,
            'contact_id'    => $contact->id,
            'property_id'   => $property?->id,
            'agent_id'      => $agent->id,
            'channel'       => $channel,
            'source'        => $source,
            'body_snapshot' => $body,
            // status defaults to READY via the model; no due_at (vestigial column).
        ]);

        return [
            'ok'      => true,
            'status'  => 200,
            'row'     => $row,
            'message' => 'Added to your outreach queue — ready to send in the send-window.',
        ];
    }
}
