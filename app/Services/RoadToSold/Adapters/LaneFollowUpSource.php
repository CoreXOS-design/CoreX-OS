<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\ProspectService;
use Carbon\CarbonInterface;

/**
 * FollowUpSource backed by lane 3's call log: OutcomeService::followUpsDue() — the current outcome on
 * each property the agent worked whose return date has arrived (overdue ones included, dropped and
 * never-again ones excluded).
 */
final class LaneFollowUpSource implements FollowUpSource
{
    public function dueOn(User $user, CarbonInterface $date, string $track): array
    {
        $out = [];
        $seen = [];
        // Prospects first (road-to-sold.md 22.3): a next action whose date has come, and parked items whose revisit date has come.
        foreach (app(ProspectService::class)->dueFollowUps($user, $date, $track) as $p) {
            $seen[(int) $p->tracked_property_id] = true;
            $parked = $p->parked_at !== null;
            $out[] = new FollowUp(
                trackedPropertyId: (int) $p->tracked_property_id,
                prospectingListingId: $p->prospecting_listing_id ? (int) $p->prospecting_listing_id : null,
                propertyId: $p->property_id ? (int) $p->property_id : null,
                reason: $parked
                    ? 'Back from parked (' . ($p->park_reason_label ?: 'set aside') . '), due ' . $p->parked_until->format('j M')
                    : $this->describe($p),
                facts: $parked
                    ? ['kind' => 'back_from_parked', 'reason' => $p->park_reason_label, 'parked_on' => $p->parked_at?->toDateString(), 'due' => $p->parked_until->toDateString(), 'from_stage' => $p->parked_from_stage]
                    : ['kind' => 'follow_up', 'action' => $p->next_action_label, 'due' => $p->next_action_at?->toDateString(), 'tries' => (int) $p->tries, 'stage' => $p->stage],
            );
        }
        foreach (app(OutcomeService::class)->followUpsDue($user, $date, $track) as $o) {
            if (isset($seen[(int) $o->tracked_property_id])) {
                continue;
            }
            $out[] = new FollowUp(
                trackedPropertyId: $o->tracked_property_id ? (int) $o->tracked_property_id : null,
                prospectingListingId: $o->prospecting_listing_id ? (int) $o->prospecting_listing_id : null,
                propertyId: $o->property_id ? (int) $o->property_id : null,
                reason: 'Follow-up: ' . ($o->outcome_label ?: 'call back') . ', due ' . $o->next_action_at->format('j M'),
                facts: ['kind' => 'follow_up', 'outcome' => $o->outcome_label, 'due' => $o->next_action_at->toDateString(), 'tries' => (int) $o->no_answer_streak],
            );
        }

        return $out;
    }

    /** The line a follow-up reads as: the call that set it ("Follow-up: Spoke - call back, due 13 Oct") or, with no call behind it, the next action. */
    private function describe(\App\Models\RoadToSold\RtsProspect $p): string
    {
        $outcome = $p->last_outcome_id
            ? \App\Models\RoadToSold\RtsContactOutcome::query()->withoutGlobalScopes()->whereKey($p->last_outcome_id)->value('outcome_label')
            : null;
        $due = $p->next_action_at->format('j M');

        return $outcome ? "Follow-up: {$outcome}, due {$due}" : (($p->next_action_label ?: 'Follow-up') . ", due {$due}");
    }
}
