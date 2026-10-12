<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalPropertyFaultSetting as Row;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use App\Support\PortalLink;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.6 - the page behind "I do not agree with this" in the owner's email. The link is SIGNED (it names
 * this owner, this property and the version the email was written for). A GET - which a mail scanner may well make - only ASKS; the POST (the owner's
 * own press) records the dispute. Nothing here needs a login: the signature is the proof the link was written for this person.
 */
class RentalFaultSettingsDisputeController extends Controller
{
    public function handle(Request $request, int $property, int $contact, RentalOwnerFaultSettingsService $service)
    {
        $prop = Property::withoutGlobalScopes()->find($property);
        $owner = Contact::withoutGlobalScopes()->find($contact);
        $row = $prop ? Row::withoutGlobalScopes()->where('property_id', $prop->id)->first() : null;
        abort_unless($prop && $owner && (int) $owner->agency_id === (int) $prop->agency_id && $service->owners($prop)->contains('id', $owner->id), 404);

        $agency = $prop->agency;
        $version = (int) $request->query('v', 0);
        $view = [
            'agencyName' => $agency?->trading_name ?: ($agency?->name ?: config('app.name', 'CoreX OS')),
            'address' => $prop->buildDisplayAddress() ?: ('Property #' . $prop->id),
            'ownerName' => trim((string) $owner->first_name) ?: 'there',
            'lines' => $row ? $service->valuesSentence(['repair_limit' => $row->repair_limit, 'emergency_limit' => $row->emergency_limit, 'callout_mode' => $row->callout_mode, 'callout_limit' => $row->callout_limit]) : '',
            'portalUrl' => PortalLink::forContact($owner, PortalLink::VIEW_OWNER, ['handling' => $prop->id]),
            'state' => 'ask',
        ];

        if (! $row) {
            $view['state'] = 'none';
        } elseif ($row->status === Row::STATUS_DISPUTED) {
            $view['state'] = 'already';
        } elseif ((int) $row->version !== $version) {
            $view['state'] = 'stale';
        } elseif ($request->isMethod('post')) {
            $view['state'] = $service->dispute($prop, $owner, $version, $request->input('reason')) === 'recorded' ? 'recorded' : 'stale';
        }

        return response()->view('rentals.fault-settings-dispute', $view);
    }
}
