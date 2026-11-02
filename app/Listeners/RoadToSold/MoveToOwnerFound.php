<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Prospecting\OwnerLinkedToTrackedProperty;
use App\Events\Prospecting\TrackedPropertyEnriched;
use App\Models\User;
use App\Services\RoadToSold\OwnerStationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v4, station 3 (road-to-sold.md 23.6.4): the owner is linked, or a deed / CMA Info record arrives later, and the belt record of that
 * property moves to Owner found when the rule is met. Never throws to the caller.
 */
class MoveToOwnerFound
{
    public function __construct(private readonly OwnerStationService $owner)
    {
    }

    public function handleOwnerLinked(OwnerLinkedToTrackedProperty $e): void
    {
        $this->run((int) $e->agencyId, (int) $e->trackedPropertyId, $e->actorUserId ?? null, 'OwnerLinkedToTrackedProperty');
    }

    public function handleEnriched(TrackedPropertyEnriched $e): void
    {
        $deed = in_array($e->sourceType, OwnerStationService::DEED_SOURCES, true) || in_array('deeds_captured_at', $e->fieldsAdded, true);
        if ($deed) {
            $this->run((int) $e->agencyId, (int) $e->trackedPropertyId, $e->actorUserId, 'TrackedPropertyEnriched');
        }
    }

    private function run(int $agencyId, int $trackedPropertyId, ?int $actorId, string $event): void
    {
        try {
            $actor = $actorId ? User::query()->withoutGlobalScopes()->find($actorId) : null;
            $this->owner->evaluate($agencyId, $trackedPropertyId, $actor, $event);
        } catch (Throwable $ex) {
            Log::warning('Belt: Owner found could not be evaluated (the save stands)', ['tracked_property_id' => $trackedPropertyId, 'message' => $ex->getMessage()]);
        }
    }
}
