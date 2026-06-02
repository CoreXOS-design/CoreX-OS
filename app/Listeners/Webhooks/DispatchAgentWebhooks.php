<?php

namespace App\Listeners\Webhooks;

use App\Events\Website\AgentVisibilityChanged;
use App\Http\Resources\WebsiteApi\AgentResource;
use App\Jobs\DeliverAgencyWebhook;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Scopes\AgencyScope;

/**
 * Fans an AgentVisibilityChanged event out to every website (API key) of the
 * agent's agency that can receive webhooks. Agency-wide (agents aren't
 * per-property). Respects the master switch + webhooks:receive scope + url.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1, §6.2
 */
class DispatchAgentWebhooks
{
    public function handle(AgentVisibilityChanged $event): void
    {
        $agent = $event->agent;
        if (!$agent->agency_id) {
            return;
        }

        $agency = $agent->agency;
        if (!$agency || !$agency->website_enabled) {
            return;
        }

        $keys = AgencyApiKey::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agent->agency_id)
            ->get()
            ->filter(fn (AgencyApiKey $k) => $k->webhook_url
                && $k->isActive()
                && $k->hasScope(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE));

        if ($keys->isEmpty()) {
            return;
        }

        $data = $event->action === 'removed'
            ? ['id' => $agent->id, 'name' => $agent->name]
            : (new AgentResource($agent))->resolve();

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
