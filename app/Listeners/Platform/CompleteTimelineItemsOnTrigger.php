<?php

namespace App\Listeners\Platform;

use App\Events\Platform\AgencyContractSigned;
use App\Events\Platform\AgencySetupWizardCompleted;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineItem;
use App\Services\Platform\AgencyTimelineService;

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

        $timeline = AgencyTimeline::where('agency_id', $event->agencyId)->first();
        if (!$timeline) {
            return;
        }

        AgencyTimelineItem::where('timeline_id', $timeline->id)
            ->where('auto_complete_trigger', $trigger)
            ->where('status', 'pending')
            ->get()
            ->each(fn ($item) => $this->timelines->setStatus($item, 'done', null, $trigger));
    }
}
