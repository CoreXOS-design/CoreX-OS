<?php

namespace App\Support\Pipeline;

use App\Models\Deal;
use Illuminate\Support\Collection;

/**
 * Pipeline Dashboard Phase 1 — the plug-in contract for the event normalizer. Each source knows how to
 * read ONE origin (comments, later email, later WhatsApp) and yield normalized PipelineEvent DTOs for a
 * deal. Register implementations on PipelineEventService (in AppServiceProvider). Adding email/WhatsApp
 * later = a new implementation here; no DTO or aggregator change. Spec §3.3
 */
interface PipelineEventSource
{
    /**
     * @return Collection<int,PipelineEvent> events this source has for the given DR1 deal.
     */
    public function eventsForDeal(Deal $deal): Collection;
}
