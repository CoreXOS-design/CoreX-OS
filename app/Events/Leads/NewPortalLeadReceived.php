<?php

namespace App\Events\Leads;

use App\Models\PortalLead;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a row is written to portal_leads — regardless of portal (P24 or PP).
 *
 * Drives the agency-scoped popup toast and is the extension point for any
 * future cross-pillar reactivity (Slack, push notifications, etc.).
 */
class NewPortalLeadReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public PortalLead $portalLead)
    {
    }
}
