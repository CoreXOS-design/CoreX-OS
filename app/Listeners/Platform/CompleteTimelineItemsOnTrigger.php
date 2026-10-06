<?php

namespace App\Listeners\Platform;

use App\Events\Platform\AgencyContractSigned;
use App\Events\Platform\AgencySetupWizardCompleted;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineItem;
use App\Services\Platform\AgencyTimelineService;
use Illuminate\Support\Facades\Log;

/**
 * Ticks off the timeline items that are waiting on a real-world event:
 *   AgencyContractSigned        → items with trigger `contract_signed`
 *   AgencySetupWizardCompleted  → items with trigger `setup_wizard_completed`
 *
 * SYNC on purpose (domain events carry readonly state a queued listener cannot
 * restore — see AgencyFeatureToggled). Idempotent: only PENDING items change, so
 * a replay does nothing. Spec: agency-timeline-and-platform-esign.md §9.
 */
class CompleteTimelineItemsOnTrigger
{
    public function __construct(private AgencyTimelineService $timelines)
    {
    }

    public function handle(AgencyContractSigned|AgencySetupWizardCompleted $event): void
    {
        $trigger = $event instanceof AgencyContractSigned ? 'contract_signed' : 'setup_wizard_completed';

        // A timeline fault must never break the thing that fired the event (e.g. the wizard's
        // /finish after completed_at is saved) — log it and carry on. The catch-up paths
        // (timeline start/restore reconcile) pick a missed tick up later.
        try {
            AgencyTimeline::where('agency_id', $event->agencyId)->get()->each(function (AgencyTimeline $timeline) use ($trigger) {
                AgencyTimelineItem::where('timeline_id', $timeline->id)
                    ->where('auto_complete_trigger', $trigger)
                    ->where('status', 'pending')
                    ->get()
                    ->each(fn ($item) => $this->timelines->setStatus($item, 'done', null, $trigger));
            });
        } catch (\Throwable $e) {
            Log::error('AgencyTimeline: auto-complete listener failed', ['trigger' => $trigger, 'agency_id' => $event->agencyId, 'error' => $e->getMessage()]);
        }
    }
}
