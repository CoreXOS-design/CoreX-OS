<?php

namespace App\Listeners\Leads;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sends an FCM push to every device token belonging to a user in the lead's
 * agency. Best-effort: silently no-ops if the FCM transport class isn't
 * registered, mirroring NotificationDispatcher::sendPush().
 *
 * Spec: .ai/specs/portal-leads.md  (mobile push surface)
 */
class PushNewPortalLeadToMobile
{
    public function handle(NewPortalLeadReceived $event): void
    {
        $lead = $event->portalLead;

        $tokens = DeviceToken::query()
            ->whereIn('user_id',
                User::query()->where('agency_id', $lead->agency_id)->pluck('id')
            )
            ->pluck('token')
            ->all();

        if (empty($tokens)) return;

        $serviceClass = '\\App\\Services\\Push\\FcmService';
        if (! class_exists($serviceClass)) return;

        $title = sprintf('New %s lead', $lead->portalLabel());
        $body  = trim(($lead->name ?: 'Unknown') . ($lead->listing_portal_ref ? ' — ' . $lead->listing_portal_ref : ''));

        try {
            app($serviceClass)->send($tokens, [
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
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
            ]);
        } catch (\Throwable $e) {
            Log::warning('Portal lead FCM push failed', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
