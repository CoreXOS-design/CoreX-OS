<?php

namespace App\Services\Deal\Pipeline;

use App\Models\Deal;
use App\Support\Pipeline\PipelineEvent;
use App\Support\Pipeline\PipelineEventSource;
use Illuminate\Support\Collection;

/**
 * Pipeline Dashboard Phase 1 — the event normalizer aggregator. Merges every registered
 * PipelineEventSource into ONE chronological stream of PipelineEvent DTOs for a deal. Sources are
 * injected (registered in AppServiceProvider); adding email/WhatsApp later = add a source there, no
 * change here. Consumers (the timeline activity lane) filter by scope/step and reverse as needed.
 * Spec: .ai/specs/pipeline-dashboard.md §3.3
 */
class PipelineEventService
{
    /** @var array<int,PipelineEventSource> */
    private array $sources;

    /**
     * @param iterable<PipelineEventSource> $sources
     */
    public function __construct(iterable $sources = [])
    {
        $this->sources = [];
        foreach ($sources as $source) {
            if ($source instanceof PipelineEventSource) {
                $this->sources[] = $source;
            }
        }
    }

    /**
     * Every event across every source for this deal, ascending by occurredAt (chronological — the
     * natural order for a left→right time axis). Ties broken by source key for stability.
     *
     * @return Collection<int,PipelineEvent>
     */
    public function eventsForDeal(Deal $deal): Collection
    {
        return collect($this->sources)
            ->flatMap(fn (PipelineEventSource $s) => $s->eventsForDeal($deal))
            ->sort(function (PipelineEvent $a, PipelineEvent $b) {
                return [$a->occurredAt->getTimestamp(), $a->key()]
                    <=> [$b->occurredAt->getTimestamp(), $b->key()];
            })
            ->values();
    }

    /**
     * Events for a single step (scope=step and stepId matches), chronological.
     *
     * @return Collection<int,PipelineEvent>
     */
    public function eventsForStep(Deal $deal, int $stepId): Collection
    {
        return $this->eventsForDeal($deal)
            ->filter(fn (PipelineEvent $e) => $e->isStepScoped() && $e->stepId === $stepId)
            ->values();
    }

    /** The registered source count — for diagnostics/tests. */
    public function sourceCount(): int
    {
        return count($this->sources);
    }
}
