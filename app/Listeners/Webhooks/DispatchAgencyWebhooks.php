<?php

namespace App\Listeners\Webhooks;

use App\Events\Website\ListingSyndicationChanged;
use App\Http\Resources\WebsiteApi\ListingResource;
use App\Jobs\DeliverAgencyWebhook;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;

/**
 * Turns a ListingSyndicationChanged domain event into per-website webhook
 * deliveries. Targets the specific website for published/removed, or fans out
 * to every website the listing is enabled on for updated. Respects the master
 * "website is live" switch, the key's webhooks:receive scope, and a configured
 * webhook_url. Each delivery is logged then handed to DeliverAgencyWebhook.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1, §6.2
 */
class DispatchAgencyWebhooks
{
    public function handle(ListingSyndicationChanged $event): void
    {
        $property = $event->property;

        // Master switch (visibility layer 1): nothing fires when offline.
        $agency = $property->agency;
        if (!$agency || !$agency->website_enabled) {
            return;
        }

        $keyIds = $event->agencyApiKeyId !== null
            ? [$event->agencyApiKeyId]
            : PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
                ->where('property_id', $property->id)->where('enabled', true)
                ->pluck('agency_api_key_id')->all();

        if (empty($keyIds)) {
            return;
        }

        $keys = AgencyApiKey::withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $keyIds)
            ->get()
            ->filter(fn (AgencyApiKey $k) => $k->webhook_url
                && $k->isActive()
                && $k->hasScope(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE));

        if ($keys->isEmpty()) {
            return;
        }

        $data = $event->action === 'removed'
            ? ['id' => $property->id, 'reference' => $property->external_id ?: (string) $property->id]
            : (new ListingResource($property->loadMissing('agent', 'secondAgent')))->resolve();

        foreach ($keys as $key) {
            $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->create([
                'agency_id'         => $key->agency_id,
                'agency_api_key_id' => $key->id,
                'event_name'        => $event->webhookEvent(),
                'payload'           => $data,
                'attempts'          => 0,
            ]);

            DeliverAgencyWebhook::dispatch($delivery->id);
        }
    }
}
