<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Events\Map\MapCmaOpened;
use App\Events\Map\MapComparableAdded;
use App\Events\Map\MapContactOwnerLaunched;
use App\Events\Map\MapPitchLaunched;
use App\Events\Map\MapWhatsAppLaunched;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\SchemeOwner;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase A.2 — fire-and-forget endpoint for map-launched actions.
 *
 * Pattern: client clicks pitch/whatsapp/etc. button → posts here → server
 * fires a domain event (which LogAgentActivity persists to
 * agent_activity_events) → returns 200 → client navigates to the real
 * destination route.
 *
 * Logging failure does NOT block the user — the client treats this as
 * fire-and-forget and proceeds to the destination regardless. The endpoint
 * still validates so we can spot mis-wired callers in dev.
 */
final class MapActivityController extends Controller
{
    /**
     * POST /corex/map/activity/log
     *
     * Body:
     *   action       — pitch_launched | whatsapp_launched | contact_owner_launched
     *                  | comparable_added | cma_opened
     *   category     — hfc_listings | sold_comps | active_listings | mic_subjects | scheme_owners
     *   record_id    — id of the subject row (int for Property/MarketReport/SchemeOwner;
     *                  string for comp_ref like "mrcr:123" / "psc:45" / "deal:9")
     *   location_key — sha256:... from the location grouper
     *   source       — composite_row | single_detail
     */
    public function log(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action'       => ['required', Rule::in([
                'pitch_launched', 'whatsapp_launched', 'contact_owner_launched',
                'comparable_added', 'cma_opened',
            ])],
            'category'     => ['required', 'string', 'max:40'],
            'record_id'    => ['required'],   // int OR string ref — validated per-action below
            'location_key' => ['required', 'string', 'max:120'],
            'source'       => ['required', Rule::in(['composite_row', 'single_detail'])],
        ]);

        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        if (!$user || !$agencyId) {
            return response()->json(['error' => 'No agency context.'], 403);
        }

        $event = match ($data['action']) {
            'pitch_launched'         => $this->pitchLaunched($data, $agencyId, $user->id),
            'whatsapp_launched'      => $this->whatsAppLaunched($data, $agencyId, $user->id),
            'contact_owner_launched' => $this->contactOwnerLaunched($data, $agencyId, $user->id),
            'comparable_added'       => $this->comparableAdded($data, $agencyId, $user->id),
            'cma_opened'             => $this->cmaOpened($data, $agencyId, $user->id),
        };

        if ($event === null) {
            return response()->json(['logged' => false, 'reason' => 'subject not found or not in this agency'], 422);
        }

        event($event);

        return response()->json([
            'logged'   => true,
            'event_id' => $event->eventId,
        ]);
    }

    private function pitchLaunched(array $data, int $agencyId, int $userId): ?MapPitchLaunched
    {
        $id = (int) $data['record_id'];
        $property = Property::withoutGlobalScopes()
            ->where('id', $id)
            ->where('agency_id', $agencyId)
            ->first();
        if (!$property) return null;

        return new MapPitchLaunched(
            property:     $property,
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
        );
    }

    private function whatsAppLaunched(array $data, int $agencyId, int $userId): ?MapWhatsAppLaunched
    {
        $id       = (int) $data['record_id'];
        $category = (string) $data['category'];

        if ($category === 'hfc_listings') {
            $property = Property::withoutGlobalScopes()
                ->where('id', $id)->where('agency_id', $agencyId)->first();
            if (!$property) return null;
            return new MapWhatsAppLaunched(
                subjectModel: $property,
                agencyId:     $agencyId,
                actingUserId: $userId,
                locationKey:  (string) $data['location_key'],
                source:       (string) $data['source'],
                propertyId:   (int) $property->id,
            );
        }

        // contact_id pathway — the composer route takes a contact, the
        // payload sometimes carries one for direct-to-contact wa.me links.
        $contact = Contact::withoutGlobalScopes()
            ->where('id', $id)->where('agency_id', $agencyId)->first();
        if (!$contact) return null;
        return new MapWhatsAppLaunched(
            subjectModel: $contact,
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
            contactId:    (int) $contact->id,
        );
    }

    private function contactOwnerLaunched(array $data, int $agencyId, int $userId): ?MapContactOwnerLaunched
    {
        $id = (int) $data['record_id'];
        $owner = SchemeOwner::withoutGlobalScopes()
            ->where('id', $id)->where('agency_id', $agencyId)->first();
        if (!$owner) return null;

        $channel = is_string($data['channel'] ?? null) ? (string) $data['channel'] : 'whatsapp';

        return new MapContactOwnerLaunched(
            owner:        $owner,
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
            channel:      $channel,
        );
    }

    private function comparableAdded(array $data, int $agencyId, int $userId): MapComparableAdded
    {
        // record_id arrives as the layer-prefixed ref string (mrcr:123 / psc:45 /
        // deal:9). No agency-bound lookup here — the destination page checks
        // permission when the agent actually adds the comp.
        return new MapComparableAdded(
            compRef:      (string) $data['record_id'],
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
        );
    }

    private function cmaOpened(array $data, int $agencyId, int $userId): ?MapCmaOpened
    {
        $id = (int) $data['record_id'];
        $report = MarketReport::withoutGlobalScopes()
            ->where('id', $id)->where('agency_id', $agencyId)->first();
        if (!$report) return null;

        return new MapCmaOpened(
            report:       $report,
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
        );
    }
}
