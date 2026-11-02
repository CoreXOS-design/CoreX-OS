<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\RoadToSoldException;
use Illuminate\Http\Request;

/**
 * road-to-sold.md 23.23 drop 4: the page after the calendar's own form saved an evaluation inside the pitch popup. It says "Evaluation booked", and when the
 * agent got here from the Phone call tab it records that call (the one contact record) against the appointment just made - the appointment is used, never
 * booked twice (OutcomeService, `calendar_event_id`).
 */
final class PitchBookController extends Controller
{
    public function done(Request $request, int $calendarEvent, OutcomeService $outcomes)
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $event = CalendarEvent::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->findOrFail($calendarEvent);
        abort_unless(in_array((int) $event->user_id, [(int) $user->id, (int) $user->ownershipUserId()], true), 403);

        $property = $event->property_id ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->find((int) $event->property_id) : null;
        $address = $property ? (trim((string) $property->composeAddressFromParts()) ?: (string) $property->address) : trim(preg_replace('/^Evaluation:\s*/i', '', (string) $event->title));
        $when = \Illuminate\Support\Carbon::parse($event->event_date);
        $whenText = $when->format('j M H:i');

        $logged = null;
        $problem = null;
        $outcomeId = (int) $request->query('pitch_outcome');
        if ($outcomeId > 0) {
            $tpId = (int) ($property?->tracked_property_id ?: ($event->tracked_property_id ?? 0));
            if ($tpId <= 0 && $property) {
                $tpId = (int) TrackedProperty::query()->where('agency_id', $agencyId)->where('promoted_to_property_id', $property->id)->value('id');
            }
            try {
                if ($tpId <= 0) {
                    throw new RoadToSoldException('This property is not on your Road to Sold, so the call was not logged.');
                }
                $logged = $outcomes->record($user, $tpId, $outcomeId, [
                    'channel' => (string) $request->query('pitch_channel', 'phone'),
                    'occurred_at' => $request->query('pitch_when') ?: null,
                    'note' => $request->query('pitch_note') ?: null,
                    'calendar_event_id' => $event->id,
                    'valuation_booked' => true,
                    'property_id' => $property?->id,
                ]);
            } catch (RoadToSoldException $e) {
                $problem = $e->getMessage();
            }
        }

        return view('seller-outreach.evaluation-booked', [
            'event' => $event, 'address' => $address, 'whenText' => $whenText, 'logged' => $logged, 'problem' => $problem,
        ]);
    }
}
