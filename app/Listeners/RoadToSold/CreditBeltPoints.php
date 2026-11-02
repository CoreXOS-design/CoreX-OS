<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Prospecting\OwnerLinkedToTrackedProperty;
use App\Events\Prospecting\TrackedPropertyEnriched;
use App\Events\RoadToSold\RoadToSoldOutcomeRecorded;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\BeltPoints;
use App\Services\RoadToSold\OwnerStationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v4 belt points (road-to-sold.md 23.16): turns the events that exist today into credits. Never throws to the caller.
 *
 *   OwnerLinkedToTrackedProperty            -> rts.owner_linked   to the agent(s) whose open belt record is on that property
 *   TrackedPropertyEnriched (a deed record) -> rts.deed_linked    same agent(s); one credit per belt record however often it re-fires
 *   RoadToSoldOutcomeRecorded               -> rts.outcome.<channel>, once per outcome; a no-answer streak pays only the first attempt
 *                                              + rts.offramp_logged when the outcome is an off-ramp (work done: not punished)
 *
 * The credits that wait for an event another lane raises (rts.evaluation_feedback, rts.advertised) are defined in BeltPoints::CREDITS; the
 * lane that raises the event calls BeltPoints::credit() with the belt record (RtsProspect) as the subject.
 */
class CreditBeltPoints
{
    public function __construct(private readonly BeltPoints $points)
    {
    }

    public function handleOutcome(RoadToSoldOutcomeRecorded $e): void
    {
        try {
            if ($e->track !== 'sales') {
                return;
            }
            $outcome = RtsContactOutcome::query()->withoutGlobalScopes()->find($e->outcomeId);
            $agent = User::query()->withoutGlobalScopes()->find($e->userId);
            if (! $outcome || ! $agent) {
                return;
            }
            $date = $outcome->occurred_at ?? now();

            $slug = BeltPoints::channelSlug($e->channel);
            if ($slug && $this->paysChannel($outcome)) {
                $this->points->credit($slug, $agent, $outcome, ['date' => $date]);
            }

            $def = RtsOutcomeDefinition::query()->withoutGlobalScopes()->where('agency_id', $e->agencyId)->where('track', $e->track)->where('key', $e->outcomeKey)->first();
            if ($def && $def->group() === RtsOutcomeDefinition::GROUP_OFF_RAMP) {
                $this->points->credit('rts.offramp_logged', $agent, $outcome, ['date' => $date]);
            }
        } catch (Throwable $ex) {
            Log::warning('Belt points: an outcome could not be credited (the save stands)', ['outcome_id' => $e->outcomeId, 'message' => $ex->getMessage()]);
        }
    }

    public function handleOwnerLinked(OwnerLinkedToTrackedProperty $e): void
    {
        $this->forHolders((int) $e->agencyId, (int) $e->trackedPropertyId, 'rts.owner_linked');
    }

    public function handleEnriched(TrackedPropertyEnriched $e): void
    {
        $deed = in_array($e->sourceType, OwnerStationService::DEED_SOURCES, true) || in_array('deeds_captured_at', $e->fieldsAdded, true);
        if ($deed) {
            $this->forHolders((int) $e->agencyId, (int) $e->trackedPropertyId, 'rts.deed_linked');
        }
    }

    /** A no-answer streak pays the first attempt only: an attempt right after another attempt on the same property earns nothing. */
    private function paysChannel(RtsContactOutcome $outcome): bool
    {
        if ($outcome->kind !== RtsOutcomeDefinition::KIND_ATTEMPT) {
            return true;
        }
        $previous = RtsContactOutcome::query()->withoutGlobalScopes()
            ->where('agency_id', $outcome->agency_id)->where('track', $outcome->track)->where('tracked_property_id', $outcome->tracked_property_id)
            ->where('user_id', $outcome->user_id)->where('id', '<', $outcome->id)->orderByDesc('id')->first(['kind']);

        return ! $previous || $previous->kind !== RtsOutcomeDefinition::KIND_ATTEMPT;
    }

    /** The agent(s) working the property (an open belt record) earn the credit; a property nobody works pays nobody. */
    private function forHolders(int $agencyId, int $trackedPropertyId, string $slug): void
    {
        try {
            $rows = RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('track', 'sales')->where('tracked_property_id', $trackedPropertyId)
                ->whereNull('closed_at')->whereNull('released_at')->whereNull('deleted_at')->get();
            foreach ($rows as $p) {
                $agent = User::query()->withoutGlobalScopes()->find((int) $p->user_id);
                $this->points->credit($slug, $agent, $p);
            }
        } catch (Throwable $ex) {
            Log::warning('Belt points: a credit could not be written (the save stands)', ['slug' => $slug, 'tracked_property_id' => $trackedPropertyId, 'message' => $ex->getMessage()]);
        }
    }
}
