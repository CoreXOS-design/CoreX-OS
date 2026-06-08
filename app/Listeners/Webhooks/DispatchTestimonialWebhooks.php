<?php

namespace App\Listeners\Webhooks;

use App\Events\Website\TestimonialVisibilityChanged;
use App\Http\Resources\WebsiteApi\TestimonialResource;
use App\Jobs\DeliverAgencyWebhook;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Scopes\AgencyScope;

/**
 * Fans a TestimonialVisibilityChanged event out to every website (API key) of
 * the testimonial's agency that can receive webhooks. Agency-wide (testimonials
 * aren't per-property). Respects the master switch + webhooks:receive scope + url.
 *
 * Spec: .ai/specs/testimonials.md §5.
 */
class DispatchTestimonialWebhooks
{
    public function handle(TestimonialVisibilityChanged $event): void
    {
        $testimonial = $event->testimonial;
        if (!$testimonial->agency_id) {
            return;
        }

        $agency = $testimonial->agency;
        if (!$agency || !$agency->website_enabled) {
            return;
        }

        $keys = AgencyApiKey::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $testimonial->agency_id)
            ->get()
            ->filter(fn (AgencyApiKey $k) => $k->webhook_url
                && $k->isActive()
                && $k->hasScope(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE));

        if ($keys->isEmpty()) {
            return;
        }

        $data = $event->action === 'removed'
            ? ['id' => $testimonial->id]
            : (new TestimonialResource($testimonial))->resolve();

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
