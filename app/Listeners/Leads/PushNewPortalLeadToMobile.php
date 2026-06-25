<?php

namespace App\Listeners\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\User;
use App\Services\Push\PushNotificationService;

/**
 * Sends an FCM push to the agent(s) who own the lead — the listing's primary +
 * second agent and, when the enquirer matched an existing contact, that
 * contact's agent (see PortalLead::agentIds()). It does NOT fan out to the
 * whole agency: an agent with no link to the listing is not notified.
 *
 * Routed through PushNotificationService so re-fires are guarded: the
 * idempotency key is the LEAD itself ("portal_lead:{id}"), so if this event is
 * re-fired for the same lead — by the 5-minute P24 poller re-processing a
 * batch, a re-saved row, or a re-import — each device is still buzzed at most
 * once. The per-device rate cap is the hard backstop for a genuine burst of
 * distinct leads.
 *
 * Spec: .ai/specs/portal-leads.md (mobile push surface)
 *       .ai/specs/push-notifications.md (dispatch guards)
 */
class PushNewPortalLeadToMobile
{
    public function __construct(private PushNotificationService $push) {}

    public function handle(NewPortalLeadReceived $event): void
    {
        $lead = $event->portalLead;

        $agentIds = $lead->agentIds();
        if (empty($agentIds)) return;

        // Safety: only notify users who actually belong to the lead's agency.
        // Defends against a stale cross-agency agent assignment on the listing
        // (the same class of bug fixed in the property/deal scanners).
        $userIds = User::query()
            ->whereIn('id', $agentIds)
            ->where('agency_id', $lead->agency_id)
            ->pluck('id')
            ->all();

        if (empty($userIds)) return;

        $payload = [
            'notification' => [
                'title' => sprintf('New %s lead', $lead->portalLabel()),
                'body'  => trim(($lead->name ?: 'Unknown') . ($lead->listing_portal_ref ? ' — ' . $lead->listing_portal_ref : '')),
            ],
            'data' => [
                'type'                => 'portal_lead',
                'portal_lead_id'      => (string) $lead->id,
                'portal'              => (string) $lead->portal,
                'lead_type'           => (string) ($lead->lead_type ?? ''),
                'listing_id'          => (string) ($lead->listing_id ?? ''),
                'listing_portal_ref'  => (string) ($lead->listing_portal_ref ?? ''),
                'received_at'         => optional($lead->received_at)->toIso8601String() ?? '',
                'deep_link'           => '/portal-leads/' . $lead->id,
            ],
        ];

        $this->push->sendToUserIds($userIds, 'portal_lead:' . $lead->id, $payload);
    }
}
