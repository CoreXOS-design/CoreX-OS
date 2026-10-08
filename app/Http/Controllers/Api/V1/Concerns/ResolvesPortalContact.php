<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Services\ClientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-445 — mirrors ClientPortalController::resolveContact() exactly (same
 * ClientUser -> Contact resolution, same 409/404 shape) for the new
 * tenant/landlord/contractor rentals controllers, without modifying that
 * file (out of scope — this is a new, parallel audience, not a change to
 * the existing buyer/seller one).
 */
trait ResolvesPortalContact
{
    protected function resolvePortalContact(Request $request): Contact|JsonResponse
    {
        /** @var ClientUser $client */
        $client = $request->user();

        $agencyId = $client->current_agency_id;
        if (!$agencyId) {
            // A login created without an agency chosen (e.g. by the lease "send portal link") but linked to exactly ONE agency IS in that
            // agency: adopt it, exactly as the sign-in endpoints do. Without this every call answered 409 and the portal showed no tabs.
            $agencies = app(ClientAuthService::class)->agenciesFor($client);
            $agencyId = $client->locked_to_agency_id ?: (count($agencies) === 1 ? $agencies[0]['id'] : null);
            if ($agencyId) {
                $client->forceFill(['current_agency_id' => $agencyId])->save();
            }
        }
        if (!$agencyId) {
            return response()->json(['message' => 'Select an agency first.'], 409);
        }

        $contact = app(ClientAuthService::class)->contactForAgency($client, $agencyId);
        if (!$contact) {
            return response()->json(['message' => 'No contact record in this agency.'], 404);
        }

        return $contact;
    }
}
