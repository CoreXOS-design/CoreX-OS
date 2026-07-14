<?php

declare(strict_types=1);

namespace App\Listeners\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\User;
use App\Notifications\NewPortalLeadAgentNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Part 3 — sends the TARGETED in-app + email notification to the listing agent(s) for a
 * new portal lead. Mirrors PushNewPortalLeadToMobile's targeting exactly (the listing's
 * primary + second agent and the matched contact's agent via PortalLead::agentIds()),
 * intersected with the lead's agency. Never agency-wide. A notification failure must
 * never break lead ingestion.
 */
class EmailPortalLeadToAgent
{
    public function handle(NewPortalLeadReceived $event): void
    {
        $lead = $event->portalLead;

        $agentIds = $lead->agentIds();
        if (empty($agentIds)) {
            return;
        }

        try {
            $agents = User::query()
                ->whereIn('id', $agentIds)
                ->where('agency_id', $lead->agency_id)
                ->get();

            // AT-235 (S2) — through the GATEWAY, not a raw ->notify().
            //
            // This one call now covers in-app, email AND push. The push used to be a
            // separate listener (PushNewPortalLeadToMobile) firing independently on
            // the same event, which never read `notify_push` — so an agent who had
            // turned push off still got pushed (C10). One fact, one notification,
            // three channels resolved once.
            //
            // ── THE DEDUP KEY IS THE LEAD, NOT THE CLOCK ────────────────────────
            //
            // My first pass used now() here, reasoning that a lead arriving is a
            // "discrete event". That is WRONG. (I caught it by READING the storm test and
            // reasoning about the poller — NOT by the test failing: the cooldown was masking
            // it. The test now isolates the key.) The P24 poller RE-DELIVERS the same lead
            // dedup entry — so the gateway would re-notify AND re-push the same lead,
            // repeatedly. That is the 1.9M storm's exact mechanism, reintroduced on a
            // new surface.
            //
            // A re-delivered lead is the SAME FACT, not a new one. So the key is
            // stable and derived from the lead: its received_at (falling back to
            // created_at). Re-fire → same key → dedup → one notification, one push.
            // This preserves the guarantee the old push listener had via its
            // lead-keyed idempotency, and now extends it to in-app and email too.
            $gateway = app(NotificationDispatcher::class);

            foreach ($agents as $agent) {
                $gateway->send(
                    $agent,
                    'lead.portal_received',
                    $lead,
                    new NewPortalLeadAgentNotification($lead),
                    ['threshold_hit_at' => $lead->received_at ?? $lead->created_at ?? now()],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('EmailPortalLeadToAgent failed', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
