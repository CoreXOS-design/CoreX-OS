<?php

namespace App\Listeners\Webhooks;

use App\Events\Website\ArticleVisibilityChanged;
use App\Http\Resources\WebsiteApi\ArticleResource;
use App\Jobs\DeliverAgencyWebhook;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Scopes\AgencyScope;

/**
 * Fans an ArticleVisibilityChanged event out to every website (API key) of the
 * article's agency that can receive webhooks. Agency-wide (articles aren't
 * per-property). Respects the master switch + webhooks:receive scope + url.
 *
 * The 'removed' payload still carries `agent_id` (not just `id`) because the
 * consuming site keys its article cache per agent and busts it on agent_id.
 *
 * Spec: .ai/specs/agency-public-api.md §6.1.
 */
class DispatchArticleWebhooks
{
    public function handle(ArticleVisibilityChanged $event): void
    {
        $article = $event->article;
        if (!$article->agency_id) {
            return;
        }

        $agency = $article->agency;
        if (!$agency || !$agency->website_enabled) {
            return;
        }

        $keys = AgencyApiKey::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $article->agency_id)
            ->get()
            ->filter(fn (AgencyApiKey $k) => $k->webhook_url
                && $k->isActive()
                && $k->hasScope(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE));

        if ($keys->isEmpty()) {
            return;
        }

        $data = $event->action === 'removed'
            ? ['id' => $article->id, 'agent_id' => (int) $article->user_id]
            : (new ArticleResource($article))->resolve();

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
