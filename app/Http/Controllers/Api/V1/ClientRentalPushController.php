<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalClientPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-508 (P1) — a tenant's / owner's own phone-notification choices. .ai/specs/at-508-mobile-rentals.md §13.
 * Only the SWITCHABLE (purely informational) notifications are ever listed or accepted; the mandatory ones are not shown and cannot be switched off.
 * Same contract on both sides: GET lists, PUT saves { "preferences": { "<key>": true|false } }.
 */
class ClientRentalPushController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(private readonly RentalClientPushService $push) {}

    public function tenantShow(Request $request): JsonResponse
    {
        return $this->show($request, 'tenant');
    }

    public function tenantUpdate(Request $request): JsonResponse
    {
        return $this->update($request, 'tenant');
    }

    public function landlordShow(Request $request): JsonResponse
    {
        return $this->show($request, 'owner');
    }

    public function landlordUpdate(Request $request): JsonResponse
    {
        return $this->update($request, 'owner');
    }

    private function show(Request $request, string $audience): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json(['switchable' => $this->push->switchableFor($request->user(), $audience, (int) $contact->agency_id)]);
    }

    private function update(Request $request, string $audience): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $data = $request->validate(['preferences' => ['required', 'array', 'min:1'], 'preferences.*' => ['boolean']]);
        $allowed = collect($this->push->switchableFor($request->user(), $audience, (int) $contact->agency_id))->pluck('key')->all();
        foreach (array_keys($data['preferences']) as $key) {
            if (! in_array($key, $allowed, true)) {
                return response()->json(['message' => $this->push->isMandatory((string) $key) ? 'This notification cannot be switched off.' : 'Unknown notification.', 'code' => 'not_switchable'], 422);
            }
        }
        foreach ($data['preferences'] as $key => $enabled) {
            $this->push->setPreference($request->user(), $key, (bool) $enabled);
        }

        return response()->json(['switchable' => $this->push->switchableFor($request->user(), $audience, (int) $contact->agency_id)]);
    }
}
