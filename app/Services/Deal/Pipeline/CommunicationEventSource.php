<?php

namespace App\Services\Deal\Pipeline;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Support\Pipeline\PipelineEvent;
use App\Support\Pipeline\PipelineEventSource;
use Illuminate\Support\Collection;

/**
 * Pipeline Dashboard Phase 4 — email + WhatsApp events for the activity lane. Reads the comms archive
 * (communications) linked to the deal via the polymorphic communication_links (morph → the DR2 twin,
 * App\Models\DealV2\DealV2). `channel` becomes the event type (email|whatsapp); comms carry a real
 * `direction`. They are DEAL-scoped (the archive links to a deal/contact/property, never a step) until
 * a step link exists. This is the "plug in later" source promised in Phase 1 — the DTO/aggregator are
 * unchanged; it just registers alongside CommentEventSource. Spec §3.3, Phase 4.
 */
class CommunicationEventSource implements PipelineEventSource
{
    public function eventsForDeal(Deal $deal): Collection
    {
        // Comms link to the DR2 TWIN (deals_v2), not the DR1 deal. No twin → nothing to show.
        if (! $deal->deal_v2_id) {
            return collect();
        }

        $commIds = CommunicationLink::query()
            ->where('linkable_type', DealV2::class)
            ->where('linkable_id', $deal->deal_v2_id)
            ->pluck('communication_id')->unique();

        if ($commIds->isEmpty()) {
            return collect();
        }

        return Communication::with('owner')
            ->whereIn('id', $commIds)
            ->get()
            ->map(fn (Communication $c) => new PipelineEvent(
                type: $c->channel,                                   // email | whatsapp
                occurredAt: $c->occurred_at ?? $c->captured_at ?? $c->created_at,
                scope: PipelineEvent::SCOPE_DEAL,                    // comms are deal-scoped, not per-step
                stepId: null,
                direction: $c->direction,                           // inbound | outbound
                authorId: $c->owner_user_id,
                authorName: $c->owner?->name ?: ($c->from_identifier ?: null),
                body: (string) ($c->body_display ?: ($c->body_text ?: $c->body_preview)),
                sourceType: 'communication',
                sourceId: (int) $c->id,
            ))
            ->values();
    }
}
