<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Marketing\ViewingPackCreated;
use App\Models\ViewingPack;
use App\Support\Events\SafeRaise;
use Illuminate\Support\Facades\Log;

/**
 * Raises ViewingPackCreated once when a viewing pack is made (road-to-sold.md step 8c). Watching the model, not the three
 * controller actions that create one (the Viewing Packs screen, "launch from a booked viewing", "regenerate for a viewing"),
 * means no path is missed. Never breaks the save: any failure is logged and swallowed.
 */
final class ViewingPackObserver
{
    public function created(ViewingPack $pack): void
    {
        try {
            SafeRaise::dispatch('ViewingPackCreated', fn () => new ViewingPackCreated(
                packId: (int) $pack->id,
                contactId: $pack->contact_id !== null ? (int) $pack->contact_id : null,
                calendarEventId: $pack->calendar_event_id !== null ? (int) $pack->calendar_event_id : null,
                agencyId: $pack->agency_id !== null ? (int) $pack->agency_id : null,
                branchId: $pack->branch_id !== null ? (int) $pack->branch_id : null,
                userId: $pack->agent_id !== null ? (int) $pack->agent_id : (auth()->id() !== null ? (int) auth()->id() : null),
                actorUserId: auth()->id() !== null ? (int) auth()->id() : null,
            ));
        } catch (\Throwable $e) {
            Log::warning('ViewingPackCreated hook caught exception, pack save proceeds', [
                'pack_id' => $pack->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
