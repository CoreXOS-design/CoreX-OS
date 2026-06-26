<?php

declare(strict_types=1);

namespace App\Listeners\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\User;
use App\Notifications\NewPortalLeadAgentNotification;
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

            foreach ($agents as $agent) {
                $agent->notify(new NewPortalLeadAgentNotification($lead));
            }
        } catch (\Throwable $e) {
            Log::warning('EmailPortalLeadToAgent failed', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
