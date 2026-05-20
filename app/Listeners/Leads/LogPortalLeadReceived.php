<?php

namespace App\Listeners\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight listener that records every NewPortalLeadReceived event to the
 * application log. The user-visible toast is driven by Alpine polling the
 * portal-leads poll endpoint for rows with notified_at IS NULL — this listener
 * intentionally does NOT set notified_at, so polling remains the
 * single source of truth for "shown to user".
 *
 * Future integrations (Slack, push, email digests) hook in alongside this.
 */
class LogPortalLeadReceived
{
    public function handle(NewPortalLeadReceived $event): void
    {
        $lead = $event->portalLead;

        Log::channel('single')->info('Portal lead received', [
            'id'        => $lead->id,
            'agency_id' => $lead->agency_id,
            'portal'    => $lead->portal,
            'lead_type' => $lead->lead_type,
            'contact_id'=> $lead->contact_id,
            'listing_id'=> $lead->listing_id,
            'exists'    => $lead->contact_exists,
        ]);
    }
}
