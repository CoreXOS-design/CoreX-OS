<?php

namespace App\Services\Deal\Pipeline;

use App\Models\Deal;
use App\Models\DealV2\DealStepComment;
use App\Models\DealV2\DealStepInstance;
use App\Support\Pipeline\PipelineEvent;
use App\Support\Pipeline\PipelineEventSource;
use Illuminate\Support\Collection;

/**
 * Pipeline Dashboard Phase 1 — the LIVE event source: pipeline step comments (deal_step_comments).
 * Each comment normalizes to a step-scoped PipelineEvent (comments have no direction). This is the only
 * source wired today; email + WhatsApp sources plug in later behind the same interface. Spec §3.3
 */
class CommentEventSource implements PipelineEventSource
{
    public function eventsForDeal(Deal $deal): Collection
    {
        // The deal's step ids (include removed steps — their comment history is still real).
        $stepIds = DealStepInstance::withTrashed()
            ->where('dr1_deal_id', $deal->id)
            ->pluck('id');

        if ($stepIds->isEmpty()) {
            return collect();
        }

        return DealStepComment::with('user')
            ->whereIn('deal_step_instance_id', $stepIds)
            ->orderBy('created_at')
            ->get()
            ->map(fn (DealStepComment $c) => new PipelineEvent(
                type: 'comment',
                occurredAt: $c->created_at,
                scope: PipelineEvent::SCOPE_STEP,
                stepId: (int) $c->deal_step_instance_id,
                direction: null,
                authorId: $c->user_id,
                authorName: $c->user?->name,
                body: (string) $c->body,
                sourceType: 'deal_step_comment',
                sourceId: (int) $c->id,
            ))
            ->values();
    }
}
