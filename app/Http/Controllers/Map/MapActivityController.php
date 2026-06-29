<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Events\Map\MapCmaOpened;
use App\Events\Map\MapComparableAdded;
use App\Events\Map\MapContactOwnerLaunched;
use App\Events\Map\MapIdCopied;
use App\Events\Map\MapListingOpened;
use App\Events\Map\MapPitchLaunched;
use App\Events\Map\MapProspectLaunched;
use App\Events\Map\MapProspectOverride;
use App\Events\Map\MapWhatsAppLaunched;
use App\Http\Controllers\Controller;
use App\Services\Map\MapProspectStatusService;
use App\Models\Contact;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\SchemeOwner;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\AgencyScope;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
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
                // Phase A.2.1 — Prospect Now from competitor active listings.
                'prospect_launched',
                // Phase A.2.3 — portal-strip click on an HFC listing.
                'listing_opened',
                // Phase A.2.4 — Copy ID click on a sensitive fact (PII audit).
                'id_copied',
                // Phase A.2.5 — override coordinate-with-X collision warning.
                'prospect_override',
            ])],
            'category'             => ['required', 'string', 'max:40'],
            'record_id'            => ['required'],   // int OR string ref — validated per-action below
            'location_key'         => ['required', 'string', 'max:120'],
            'source'               => ['required', Rule::in(['composite_row', 'single_detail'])],
            // prospect_launched optional inputs — when tracked_property_id is
            // already known the client passes it through; otherwise the
            // controller calls match-or-create using the address/GPS facts.
            'tracked_property_id'  => ['sometimes', 'nullable', 'integer'],
            'address'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude'             => ['sometimes', 'nullable', 'numeric'],
            'longitude'            => ['sometimes', 'nullable', 'numeric'],
            'suburb'               => ['sometimes', 'nullable', 'string', 'max:120'],
            // A.2.3 — portal strip on HFC listings.
            'portal'               => ['sometimes', 'nullable', Rule::in(['p24', 'pp', 'hfc'])],
            // A.2.5 — override reason text (≥ 20 chars enforced when action=prospect_override).
            'property_id'          => ['sometimes', 'nullable', 'integer'],
            'override_reason'      => ['sometimes', 'nullable', 'string', 'min:20', 'max:1000'],
            'original_agent_id'    => ['sometimes', 'nullable', 'integer'],
            'original_agent_name'  => ['sometimes', 'nullable', 'string', 'max:120'],
            'days_in_state'        => ['sometimes', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        if (!$user || !$agencyId) {
            return response()->json(['error' => 'No agency context.'], 403);
        }

        // AT-117 §4a — send-window lock for DIRECT WhatsApp dispatches from the map.
        // Only contact_owner_launched (scheme-owner wa.me deep link) is a real
        // dispatch; whatsapp_launched/pitch_launched/prospect_launched NAVIGATE to
        // the Seller-Outreach composer (prepare), which enforces the window at its
        // own submit — blocking them here would wrongly prevent preparing in closed
        // hours. The client also intercepts the wa.me open (the authoritative
        // prevent, since the deep-link opens client-side).
        if ($data['action'] === 'contact_owner_launched') {
            $agency = \App\Models\Agency::find($agencyId);
            $window = app(\App\Services\Outreach\OutreachWindowService::class);
            if ($agency && !$window->isSendAllowed($agency)) {
                return response()->json([
                    'error'               => 'send_window_blocked',
                    'send_window_blocked' => true,
                    'message'             => $window->blockedMessage($agency),
                ], 422);
            }
        }

        // Extras carry per-action server-resolved context — e.g. for
        // prospect_launched the match-or-create result (tracked_property_id +
        // redirect_url) so the client can navigate to opportunities.show.
        $extras = [];
        $event = match ($data['action']) {
            'pitch_launched'         => $this->pitchLaunched($data, $agencyId, $user->id),
            'whatsapp_launched'      => $this->whatsAppLaunched($data, $agencyId, $user->id),
            'contact_owner_launched' => $this->contactOwnerLaunched($data, $agencyId, $user->id),
            'comparable_added'       => $this->comparableAdded($data, $agencyId, $user->id),
            'cma_opened'             => $this->cmaOpened($data, $agencyId, $user->id),
            'prospect_launched'      => $this->prospectLaunched($data, $agencyId, $user->id, $extras),
            'listing_opened'         => $this->listingOpened($data, $agencyId, $user->id),
            'id_copied'              => $this->idCopied($data, $agencyId, $user->id),
            'prospect_override'      => $this->prospectOverride($data, $agencyId, $user->id),
        };

        if ($event === null) {
            return response()->json(['logged' => false, 'reason' => 'subject not found or not in this agency'], 422);
        }

        event($event);

        return response()->json(array_merge([
            'logged'   => true,
            'event_id' => $event->eventId,
        ], $extras));
    }

    private function pitchLaunched(array $data, int $agencyId, int $userId): ?MapPitchLaunched
    {
        $id = (int) $data['record_id'];
        $property = Property::withoutGlobalScope(AgencyScope::class)
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
            $property = Property::withoutGlobalScope(AgencyScope::class)
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
        $contact = Contact::withoutGlobalScope(AgencyScope::class)
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
        $owner = SchemeOwner::withoutGlobalScope(AgencyScope::class)
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

    /**
     * Phase A.2.5 — agent overrode the coordinate-with-X collision prompt.
     * Reason is required (≥ 20 chars, validated at request boundary).
     * The override fires MapProspectOverride for BM oversight + then the
     * caller separately dispatches prospect_launched as usual — this
     * endpoint only audits the override decision; it does not perform the
     * subsequent navigation.
     */
    private function prospectOverride(array $data, int $agencyId, int $userId): ?MapProspectOverride
    {
        if (empty($data['override_reason']) || empty($data['property_id'])) {
            return null;
        }

        $property = Property::withoutGlobalScope(AgencyScope::class)
            ->where('id', (int) $data['property_id'])
            ->where('agency_id', $agencyId)
            ->first();
        if (!$property) return null;

        return new MapProspectOverride(
            property:           $property,
            agencyId:           $agencyId,
            actingUserId:       $userId,
            originalAgentId:    isset($data['original_agent_id']) ? (int) $data['original_agent_id'] : null,
            originalAgentName:  $data['original_agent_name'] ?? null,
            daysInState:        (int) ($data['days_in_state'] ?? 0),
            reason:             (string) $data['override_reason'],
            locationKey:        (string) $data['location_key'],
            source:             (string) $data['source'],
        );
    }

    /**
     * Phase A.2.4 — Copy ID click on a sensitive fact. Logs the click for
     * PII audit; never sees the actual ID value (the value lives only in
     * the agent's clipboard, copied client-side from the data attribute).
     */
    private function idCopied(array $data, int $agencyId, int $userId): ?MapIdCopied
    {
        return new MapIdCopied(
            recordId:     (string) $data['record_id'],
            category:     (string) $data['category'],
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
        );
    }

    /**
     * Phase A.2.3 Item 4 — portal-strip click on an HFC listing.
     * Records which portal (p24 / pp / hfc) the agent opened so we can
     * track which syndication surfaces actually get used.
     */
    private function listingOpened(array $data, int $agencyId, int $userId): ?MapListingOpened
    {
        $id = (int) $data['record_id'];
        $property = Property::withoutGlobalScope(AgencyScope::class)
            ->where('id', $id)
            ->where('agency_id', $agencyId)
            ->first();
        if (!$property) return null;

        return new MapListingOpened(
            property:     $property,
            agencyId:     $agencyId,
            actingUserId: $userId,
            locationKey:  (string) $data['location_key'],
            source:       (string) $data['source'],
            portal:       (string) ($data['portal'] ?? 'unknown'),
        );
    }

    private function cmaOpened(array $data, int $agencyId, int $userId): ?MapCmaOpened
    {
        $id = (int) $data['record_id'];
        $report = MarketReport::withoutGlobalScope(AgencyScope::class)
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

    /**
     * Phase A.2.1 — "Prospect Now" on a competitor active listing.
     * Phase A.2.7 — destination changed from opportunities.show to the
     * seller-outreach compose flow. A prospecting_listings row is the route
     * model binding for compose, so we find-or-create one from the MRCR/PAL
     * source data when the map record isn't already a native prospecting
     * listing.
     *
     * Record-id shape on the map today:
     *   "mrcr:N"  — comes from a market_report_comp_rows row_type=listing.
     *               Find-or-create a prospecting_listing keyed by
     *               (agency, portal_source='p24', portal_ref='mrcr:N')
     *               so the same MRCR row always maps to the same
     *               prospecting_listing record (idempotent).
     *   "pal:N"   — comes from presentation_active_listings. Same idea,
     *               keyed by portal_ref='pal:N'.
     *   integer   — a future direct prospecting_listings source. Use as-is.
     */
    private function prospectLaunched(array $data, int $agencyId, int $userId, array &$extras): ?MapProspectLaunched
    {
        $tp = null;

        if (!empty($data['tracked_property_id'])) {
            $tp = TrackedProperty::withoutGlobalScope(AgencyScope::class)
                ->where('id', (int) $data['tracked_property_id'])
                ->where('agency_id', $agencyId)
                ->first();
        }

        if ($tp === null) {
            $facts = array_filter([
                'address'   => $data['address']   ?? null,
                'latitude'  => isset($data['latitude'])  ? (float) $data['latitude']  : null,
                'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                'suburb'    => $data['suburb']    ?? null,
            ], static fn ($v) => $v !== null && $v !== '');

            if (empty($facts)) {
                return null;
            }

            try {
                $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
                    agencyId:     $agencyId,
                    facts:        $facts,
                    source:       [
                        'type' => 'map_prospect_launch',
                        'ref'  => (string) ($data['record_id'] ?? $data['location_key']),
                        'payload' => null,
                    ],
                    actorUserId: $userId,
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Route to the compose flow via find-or-create prospecting_listing.
        // When resolution fails (soft-deleted row, cross-agency row, or
        // record_id outside the accepted patterns) we DELIBERATELY return
        // no redirect_url and stamp pitch_unavailable. The client surfaces
        // an explicit error toast instead of silently bouncing the agent
        // to the MIC Opportunities tab — that behaviour (silent MIC
        // fallback) was the source of the "loads MIC" confusion in
        // .ai/specs/mic-pitch-flow-trace.md §8 and is now disallowed.
        $plId = $this->resolveProspectingListingId($data, $agencyId, $userId, (int) $tp->id);

        $extras['tracked_property_id']    = (int) $tp->id;
        $extras['prospecting_listing_id'] = $plId;

        if ($plId === null) {
            $extras['redirect_url'] = null;
            $extras['error']        = 'pitch_unavailable';
            $extras['error_message'] = "Couldn't start a pitch for this record — it may have been removed or belongs to another agency. Refresh the map and try again.";
        } else {
            // Mirror EntryPointController::resolveCollisionForListing
            // at THIS layer so the JS sees the collision and shows a
            // toast — instead of opening the entry-point form in a new
            // tab and letting THAT redirect silently to MIC (which is
            // how the user reported "Prospect Now → MIC" even after
            // 2adf9698's silent-MIC fix). For 'held' / 'own_draft' we
            // route the popup straight to the property record; for
            // 'other_draft' the popup closes and an explanatory toast
            // tells the agent to use the override flow.
            $collision = $this->preCheckProspectCollision($plId, $agencyId, $userId, $tp->id);

            if ($collision !== null) {
                $extras['redirect_url']  = $collision['redirect_url'];
                $extras['error']         = $collision['error'];
                $extras['error_message'] = $collision['error_message'];
                $extras['collision']     = $collision['collision'] ?? null;
            } else {
                $extras['redirect_url'] = route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $plId]);
            }
        }

        return new MapProspectLaunched(
            trackedProperty: $tp,
            agencyId:        $agencyId,
            actingUserId:    $userId,
            locationKey:     (string) $data['location_key'],
            source:          (string) $data['source'],
        );
    }

    /**
     * Find-or-create a prospecting_listings row to back the compose flow.
     * Returns null on failure (caller falls back to opportunities.show).
     *
     * Idempotency key is (agency_id, portal_source, portal_ref) — the
     * unique index on prospecting_listings. For MRCR/PAL records the
     * portal_ref carries the layer-prefixed source ref ("mrcr:N" / "pal:N")
     * so the same map record always maps to the same listing.
     */
    /**
     * Run the same collision check EntryPointController::resolveCollisionForListing
     * runs server-side AFTER navigation. We run it BEFORE returning a
     * redirect_url so the client can show a toast (other_draft) or open
     * the property directly (held / own_draft) without bouncing the
     * popup through the entry-point's HTML render.
     *
     * Returns null when the listing is available / previously_*. Returns
     * a {redirect_url, error, error_message, collision} dict when the
     * client should be diverted.
     */
    private function preCheckProspectCollision(int $plId, int $agencyId, int $userId, int $trackedPropertyId): ?array
    {
        $listing = \DB::table('prospecting_listings')
            ->where('id', $plId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first(['id', 'address', 'suburb', 'tracked_property_id']);
        if (!$listing) return null;

        // Prefer the prospecting_listings.tracked_property_id link; fall
        // back to the TrackedProperty we just resolved via matchOrCreate
        // (covers freshly-captured listings whose tracked_property_id
        // hasn't been backfilled yet — without this, GPS-based collision
        // detection silently no-ops and an other_draft collision wouldn't
        // be caught.)
        $tpIdForGps = $listing->tracked_property_id ?? $trackedPropertyId;
        $lat = null; $lng = null;
        if ($tpIdForGps) {
            $tpRow = \DB::table('tracked_properties')
                ->where('id', $tpIdForGps)
                ->where('agency_id', $agencyId)
                ->first(['latitude', 'longitude']);
            if ($tpRow) {
                $lat = $tpRow->latitude !== null ? (float) $tpRow->latitude : null;
                $lng = $tpRow->longitude !== null ? (float) $tpRow->longitude : null;
            }
        }

        try {
            $status = app(MapProspectStatusService::class)->resolve([
                'address'   => $listing->address ?? null,
                'latitude'  => $lat,
                'longitude' => $lng,
                'suburb'    => $listing->suburb ?? null,
            ], $agencyId, $userId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('preCheckProspectCollision resolve failed', [
                'err'        => $e->getMessage(),
                'listing_id' => $plId,
            ]);
            return null; // fail open — let the entry-point handle its own collision check
        }

        $propertyId = $status['property_id'] ?? null;

        switch ($status['status'] ?? 'available') {
            case 'held':
                return [
                    'redirect_url'  => $propertyId ? route('corex.properties.show', ['property' => $propertyId]) : null,
                    'error'         => 'pitch_blocked_held',
                    'error_message' => "Already on HFC's books — opened the property record instead.",
                    'collision'     => $status,
                ];
            case 'own_draft':
                $days = (int) ($status['days_in_state'] ?? 0);
                return [
                    'redirect_url'  => $propertyId ? route('corex.properties.show', ['property' => $propertyId]) : null,
                    'error'         => 'pitch_blocked_own_draft',
                    'error_message' => "You already have a draft on this property ({$days}d) — continuing your draft.",
                    'collision'     => $status,
                ];
            case 'other_draft':
                $agent = $status['agent_name'] ?? 'another agent';
                $days  = (int) ($status['days_in_state'] ?? 0);
                $state = $status['state_label'] ?? 'draft';
                return [
                    'redirect_url'  => null,
                    'error'         => 'pitch_blocked_other_draft',
                    'error_message' => "{$agent} has a draft on this property ({$days}d in {$state}). Coordinate with them, or use the map's override flow to take over — Prospect Now is blocked while their draft is active.",
                    'collision'     => $status,
                ];
            case 'available':
            case 'previously_sold':
            case 'previously_held':
            default:
                return null;
        }
    }

    private function resolveProspectingListingId(array $data, int $agencyId, int $userId, int $trackedPropertyId): ?int
    {
        $recordId = (string) ($data['record_id'] ?? '');
        if ($recordId === '') return null;

        // Case 1 — record_id is purely numeric → native prospecting_listing.
        if (ctype_digit($recordId)) {
            $native = \DB::table('prospecting_listings')
                ->where('id', (int) $recordId)
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->value('id');
            return $native ? (int) $native : null;
        }

        // Case 2 — layer-prefixed (mrcr:N / pal:N / deal:N) → synthesise.
        if (!preg_match('/^(mrcr|pal|deal):\d+$/', $recordId)) return null;

        $existing = \DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)
            ->where('portal_source', 'p24')   // schema enum; synthetic entries default to p24
            ->where('portal_ref', $recordId)
            ->whereNull('deleted_at')
            ->value('id');
        if ($existing) return (int) $existing;

        // Create. Pull price from MRCR/PAL when we have it on the payload.
        try {
            $now = now();
            $id = \DB::table('prospecting_listings')->insertGetId([
                'agency_id'           => $agencyId,
                'captured_by_user_id' => $userId,
                'tracked_property_id' => $trackedPropertyId,
                'portal_source'       => 'p24',
                'portal_ref'          => $recordId,
                'portal_url'          => '',   // not known for MRCR-derived listings; agent fills via compose flow
                'address'             => mb_substr((string) ($data['address'] ?? 'Unknown'), 0, 255),
                'suburb'              => mb_substr((string) ($data['suburb'] ?? ''), 0, 100),
                'price'               => 0,    // not known; the compose form is what the agent uses next
                'first_seen_at'       => $now,
                'last_seen_at'        => $now,
                'is_active'           => true,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            return (int) $id;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('resolveProspectingListingId create failed', [
                'err' => $e->getMessage(),
                'record_id' => $recordId,
            ]);
            return null;
        }
    }
}
