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
 * Pipeline Dashboard Phase 4 — email events for the activity lane. Reads the comms archive
 * (communications) linked to the deal via the polymorphic communication_links (morph → the DR2 twin,
 * App\Models\DealV2\DealV2). `channel` becomes the event type (email only — see 2026-08-20 note below);
 * comms carry a real `direction`. They are DEAL-scoped (the archive links to a deal/contact/property,
 * never a step) until a step link exists. This is the "plug in later" source promised in Phase 1 — the
 * DTO/aggregator are unchanged; it just registers alongside CommentEventSource. Spec §3.3, Phase 4.
 *
 * 2026-08-20 (Johan, live bug — deal 137/Linda's mail) — AT-231 §3.4: "Even a HIGH match is
 * PROVISIONAL until the agent verifies the first email." A link with confirmed_at still NULL is
 * exactly that provisional guess sitting in `communication_filing_suspense`, not yet accepted onto
 * the deal. This source used to render EVERY communication_links row regardless of confirmation —
 * an auto-suggested, never-verified email showed up in the deal's history indistinguishable from
 * one a human actually confirmed. Fixed: only CONFIRMED links reach the feed. Johan's explicit
 * call, and it matches the spec's own model — an unverified link isn't "filed" yet, so it has no
 * business in the deal's settled correspondence history; it belongs in suspense until verified.
 *
 * 2026-08-20 (Johan, scope call) — "whatsapp is not part of the scope... with Linda it's a mess
 * as agents talk to her about lots of deals. so theres no ways to figure out which whatsapps
 * links to which deals. so no whatsapp in deal register." A WhatsApp thread with someone like an
 * attorney spans many deals with no reliable per-message attribution, unlike email (subject/thread
 * tokens). So this source is EMAIL-only — WhatsApp is excluded at the query itself, not filtered
 * downstream, so it can never reach the feed under any $feed value.
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
            ->whereNotNull('confirmed_at')
            ->pluck('communication_id')->unique();

        if ($commIds->isEmpty()) {
            return collect();
        }

        return Communication::with('owner')
            ->whereIn('id', $commIds)
            ->where('channel', Communication::CHANNEL_EMAIL)
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
