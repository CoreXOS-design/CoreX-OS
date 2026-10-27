<?php

namespace App\Services\RoadToSold;

use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v3 - give a prospect a real CoreX property when the work needs one (road-to-sold.md 22.10, 22.11).
 *
 * An evaluation in the calendar, a pitch, a presentation and a mandate all attach to a `properties` row; a prospect found in Market
 * Intelligence starts as a tracked property only. This is the ONE place the Road to Sold flows create that row - through the existing
 * promote-to-stock path (TrackedPropertyMatchOrCreateService::promoteToStock), which matches an existing property first and otherwise
 * creates it in the agency's "prospecting" state (not live stock). The owner, when known, is linked as the owner so the appointment and the
 * pitch carry the parties. Never throws: a property that cannot be made (no branch on the agent, nothing to match on) answers null and the
 * caller carries on without one.
 */
class ProspectPropertyService
{
    public function __construct(private readonly TrackedPropertyMatchOrCreateService $promoter)
    {
    }

    public function ensureProperty(TrackedProperty $tp, User $agent): ?Property
    {
        if ($tp->promoted_to_property_id) {
            return Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($tp->promoted_to_property_id);
        }
        try {
            $property = $this->promoter->promoteToStock((int) $tp->id, (int) $agent->ownershipUserId());
        } catch (Throwable $e) {
            Log::warning('Road to Sold could not create a property for a prospect (carrying on without one)', [
                'tracked_property_id' => $tp->id, 'message' => $e->getMessage(),
            ]);

            return null;
        }
        $tp->forceFill(['promoted_to_property_id' => $property->id]);

        if ($tp->owner_contact_id) {
            try {
                $owner = Contact::query()->withoutGlobalScopes()->whereKey($tp->owner_contact_id)->where('agency_id', $tp->agency_id)->first();
                if ($owner) {
                    ContactPropertyLinker::link((int) $owner->id, (int) $property->id, 'owner');
                }
            } catch (Throwable $e) {
                Log::warning('Road to Sold could not link the owner to the new property', ['property_id' => $property->id, 'message' => $e->getMessage()]);
            }
        }

        return $property;
    }
}
