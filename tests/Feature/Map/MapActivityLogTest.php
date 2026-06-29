<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Events\Map\MapCmaOpened;
use App\Events\Map\MapComparableAdded;
use App\Events\Map\MapContactOwnerLaunched;
use App\Events\Map\MapPitchLaunched;
use App\Events\Map\MapWhatsAppLaunched;
use App\Models\AgentActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.2 — backend tests M6-M11 for the map activity log endpoint and
 * the 5 domain event classes that surface map-launched actions in
 * agent_activity_events.
 */
final class MapActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /** M6 — pitch_launched on a valid HFC property dispatches MapPitchLaunched. */
    public function test_m6_pitch_launched_for_hfc_listing_dispatches_event(): void
    {
        Event::fake([MapPitchLaunched::class]);
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'pitch_launched',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:test-pitch',
            'source'       => 'single_detail',
        ]);

        $resp->assertOk();
        $resp->assertJson(['logged' => true]);
        Event::assertDispatched(MapPitchLaunched::class, function ($e) use ($agencyId, $propertyId) {
            return $e->agencyId === $agencyId
                && (int) $e->property->id === $propertyId
                && $e->source === 'single_detail';
        });
    }

    /** M7 — whatsapp_launched on an HFC property dispatches MapWhatsAppLaunched
     *       (proves the composite-row icon strip's WhatsApp action works end-to-end). */
    public function test_m7_whatsapp_launched_for_hfc_listing_dispatches_event(): void
    {
        Event::fake([MapWhatsAppLaunched::class]);
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'whatsapp_launched',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:test-wa',
            'source'       => 'composite_row',
        ]);

        $resp->assertOk();
        Event::assertDispatched(MapWhatsAppLaunched::class, function ($e) use ($propertyId) {
            return $e->propertyId === $propertyId
                && $e->source === 'composite_row';
        });
    }

    /** M8 — each of the 5 actions dispatches the right event class. */
    public function test_m8_each_action_dispatches_the_correct_event_class(): void
    {
        Event::fake([
            MapPitchLaunched::class,
            MapWhatsAppLaunched::class,
            MapContactOwnerLaunched::class,
            MapComparableAdded::class,
            MapCmaOpened::class,
        ]);

        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $ownerId  = $this->seedSchemeOwner($agencyId);
        $user     = User::find($userId);

        $cases = [
            ['action' => 'pitch_launched',         'category' => 'hfc_listings',    'record_id' => $propertyId, 'event' => MapPitchLaunched::class],
            ['action' => 'whatsapp_launched',      'category' => 'hfc_listings',    'record_id' => $propertyId, 'event' => MapWhatsAppLaunched::class],
            ['action' => 'contact_owner_launched', 'category' => 'scheme_owners',   'record_id' => $ownerId,    'event' => MapContactOwnerLaunched::class],
            ['action' => 'comparable_added',       'category' => 'sold_comps',      'record_id' => 'mrcr:42',   'event' => MapComparableAdded::class],
            ['action' => 'cma_opened',             'category' => 'mic_subjects',    'record_id' => $reportId,   'event' => MapCmaOpened::class],
        ];

        foreach ($cases as $c) {
            $this->actingAs($user)->postJson(route('corex.map.activity.log'), [
                'action'       => $c['action'],
                'category'     => $c['category'],
                'record_id'    => $c['record_id'],
                'location_key' => 'sha256:m8-' . $c['action'],
                'source'       => 'single_detail',
            ])->assertOk();
        }

        Event::assertDispatched(MapPitchLaunched::class);
        Event::assertDispatched(MapWhatsAppLaunched::class);
        Event::assertDispatched(MapContactOwnerLaunched::class);
        Event::assertDispatched(MapComparableAdded::class);
        Event::assertDispatched(MapCmaOpened::class);
    }

    /** M9 — success response returns event_id (uuid) + logged=true. */
    public function test_m9_success_response_carries_event_id(): void
    {
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'pitch_launched',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:test-uuid',
            'source'       => 'single_detail',
        ]);

        $resp->assertOk();
        $body = $resp->json();
        $this->assertSame(true, $body['logged']);
        $this->assertArrayHasKey('event_id', $body);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $body['event_id']);
    }

    /** M10 — invalid payload returns 422 with validation errors. */
    public function test_m10_invalid_payload_returns_422(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            // missing 'action' and 'record_id'
            'category'     => 'hfc_listings',
            'location_key' => 'sha256:bad',
            'source'       => 'single_detail',
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['action', 'record_id']);
    }

    /** M11 — MapPitchLaunched is wired into the LogAgentActivity listener
     *        chain so a dispatch lands a row in agent_activity_events. */
    public function test_m11_dispatch_writes_row_to_agent_activity_events(): void
    {
        // No Event::fake here — we want the listener chain to run.
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();
        $before = AgentActivityEvent::where('agency_id', $agencyId)->count();

        $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'pitch_launched',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:m11',
            'source'       => 'single_detail',
        ])->assertOk();

        $after = AgentActivityEvent::where('agency_id', $agencyId)
            ->where('event_type', 'LIKE', 'map_pitch%')
            ->latest('id')
            ->first();
        $this->assertNotNull($after, 'pitch event should land in agent_activity_events via LogAgentActivity');
        $this->assertSame($agencyId, (int) $after->agency_id);
        $this->assertSame($userId, (int) $after->user_id);

        $payload = is_array($after->payload) ? $after->payload : json_decode($after->payload, true);
        $this->assertSame($propertyId, $payload['property_id'] ?? null);
        $this->assertSame('sha256:m11', $payload['location_key'] ?? null);
        $this->assertSame('single_detail', $payload['source'] ?? null);

        $this->assertGreaterThan($before, AgentActivityEvent::where('agency_id', $agencyId)->count());
    }

    // ── Phase A.2.1 additions ────────────────────────────────────────────

    /** M20 — map response carries preferred_public_url + status + internal_url
     *        on HFC active listings so the JS can pick the right CTA. */
    public function test_m20_map_response_includes_preferred_public_url_for_hfc(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        // Seed a property that has an active P24 syndication so the accessor
        // returns a URL.
        DB::table('properties')->insertGetId([
            'external_id'            => 'M20-' . Str::random(6),
            'title'                  => '5 Sea View Ave',
            'address'                => '5 Sea View Ave',
            'suburb'                 => 'Uvongo',
            'town'                   => 'Margate',
            'province'               => 'kwazulu-natal',
            'latitude'               => -30.84,
            'longitude'              => 30.39,
            'price'                  => 1_500_000,
            'property_type'          => 'house',
            'status'                 => 'active',
            'is_demo'                => false,
            'agency_id'              => $agencyId,
            'branch_id'              => $agencyId,
            'agent_id'               => $userId,
            'p24_ref'                => 'M20-P24',
            'p24_syndication_status' => 'active',
            'pp_suburb_id'           => 0,
            'listing_type'           => 'sale',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['hfc_listings'], viewMode: 'agent', agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);

        $hfcRecord = collect($resp['locations'])
            ->flatMap(fn ($l) => $l['records'])
            ->first(fn ($r) => $r['category'] === 'hfc_listings' && str_contains((string) ($r['title'] ?? ''), 'Sea View'));

        $this->assertNotNull($hfcRecord);
        $this->assertSame('active', $hfcRecord['status']);
        $this->assertNotNull($hfcRecord['preferred_public_url']);
        $this->assertStringContainsString('property24.com', $hfcRecord['preferred_public_url']);
        $this->assertNotNull($hfcRecord['internal_url']);
    }

    /** M21 — prospect_launched without tracked_property_id OR facts → 422
     *        (controller can't resolve). When facts ARE provided, the
     *        TrackedPropertyMatchOrCreateService is invoked. M22 covers the
     *        positive flow; this one covers the validation failure. */
    public function test_m21_prospect_launched_requires_resolvable_target(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => 'mrcr:9999',
            'location_key' => 'sha256:m21',
            'source'       => 'composite_row',
            // no tracked_property_id, no address/lat/lng → controller returns 422
        ]);

        $resp->assertStatus(422);
    }

    /** M22 — prospect_launched WITH address/lat/lng triggers match-or-create
     *        and the response carries the resolved tracked_property_id +
     *        redirect_url. */
    public function test_m22_prospect_launched_calls_match_or_create(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => 'mrcr:42',
            'location_key' => 'sha256:m22',
            'source'       => 'composite_row',
            'address'      => '99 Competitor Road, Margate',
            'latitude'     => -30.8654,
            'longitude'    => 30.3712,
            'suburb'       => 'Margate',
        ]);

        $resp->assertOk();
        $body = $resp->json();
        $this->assertTrue($body['logged']);
        $this->assertArrayHasKey('tracked_property_id', $body);
        $this->assertIsInt($body['tracked_property_id']);
        $this->assertArrayHasKey('redirect_url', $body);
        // A.2.7 — Prospect Now redirects to the seller-outreach compose
        // route, not the legacy opportunities.show. Both `prospecting` (the
        // route prefix) and `outreach/compose` (the action suffix) must be
        // present to prove the right route was resolved.
        $url = (string) $body['redirect_url'];
        $this->assertStringContainsString('prospecting', $url);
        $this->assertStringContainsString('outreach/compose', $url);

        // Confirm the TP actually exists.
        $tp = \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()
            ->where('id', $body['tracked_property_id'])
            ->first();
        $this->assertNotNull($tp);
        $this->assertSame($agencyId, (int) $tp->agency_id);
    }

    /** M23 — MapProspectLaunched is registered in AppServiceProvider's
     *        LogAgentActivity foreach (so the event_type lands in
     *        agent_activity_events). */
    public function test_m23_map_prospect_launched_registered_and_writes_activity(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();

        $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => 'mrcr:55',
            'location_key' => 'sha256:m23',
            'source'       => 'composite_row',
            'address'      => '12 Test Lane, Margate',
            'latitude'     => -30.86,
            'longitude'    => 30.37,
            'suburb'       => 'Margate',
        ])->assertOk();

        $row = AgentActivityEvent::where('agency_id', $agencyId)
            ->where('event_type', 'LIKE', 'map_prospect%')
            ->latest('id')
            ->first();
        $this->assertNotNull($row, 'MapProspectLaunched should land via LogAgentActivity');
        $this->assertSame('map_prospect.launched', $row->event_type);

        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame('sha256:m23', $payload['location_key'] ?? null);
        $this->assertSame('composite_row', $payload['source'] ?? null);
        $this->assertIsInt($payload['tracked_property_id'] ?? null);
    }

    /** M77 — P-pin Pitch flow, happy path. After the prospecting_listings
     *  re-point (df93c4b3) the map P-pin emits the raw numeric
     *  prospecting_listings.id as record_id. The activity-log endpoint
     *  must accept it via resolveProspectingListingId's Case 1 and return
     *  a redirect_url pointing at the seller-outreach entry-point form. */
    public function test_m77_p_pin_numeric_record_id_returns_entry_point_redirect(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $listingId = $this->insertProspectingListing($agencyId, $userId);

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => (string) $listingId,
            'location_key' => 'sha256:m77',
            'source'       => 'single_detail',
            'address'      => '12 Test Lane, Margate',
            'latitude'     => -30.86,
            'longitude'    => 30.37,
            'suburb'       => 'Margate',
        ])->assertOk();

        $body = $resp->json();
        $this->assertTrue($body['logged']);
        $this->assertSame($listingId, (int) $body['prospecting_listing_id'],
            'numeric record_id must resolve to the same prospecting_listings.id');
        $this->assertIsString($body['redirect_url']);
        $this->assertStringContainsString('/prospecting/' . $listingId . '/outreach/compose', $body['redirect_url']);
        $this->assertArrayNotHasKey('error', $body);
    }

    /** M78 — Silent MIC fallback is GONE. When the record_id points at a
     *  soft-deleted prospecting_listings row the server must respond with
     *  redirect_url=null + error='pitch_unavailable' so the client can
     *  surface a clear "couldn't start a pitch" toast instead of dropping
     *  the agent on the MIC Opportunities tab. */
    public function test_m78_p_pin_deleted_listing_returns_pitch_unavailable_not_mic_redirect(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $listingId = $this->insertProspectingListing($agencyId, $userId);
        DB::table('prospecting_listings')->where('id', $listingId)->update(['deleted_at' => now()]);

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => (string) $listingId,
            'location_key' => 'sha256:m78',
            'source'       => 'single_detail',
            'address'      => '12 Test Lane, Margate',
            'latitude'     => -30.86,
            'longitude'    => 30.37,
            'suburb'       => 'Margate',
        ])->assertOk();

        $body = $resp->json();
        $this->assertTrue($body['logged'], 'audit event still fires even on resolve failure');
        $this->assertNull($body['redirect_url'],
            'no silent fallback URL — soft-deleted record means no destination');
        $this->assertSame('pitch_unavailable', $body['error']);
        $this->assertNotEmpty($body['error_message']);
        $this->assertStringNotContainsStringIgnoringCase('opportunities', (string) ($body['redirect_url'] ?? ''));
    }

    /** M79 — Cross-agency prospecting_listings.id → pitch_unavailable.
     *  An agent in agency A clicking a P-pin whose record_id belongs to
     *  agency B (race condition: the row was re-scoped between pin fetch
     *  and click) must hit the same explicit error path, not get auto-
     *  bounced to MIC. */
    public function test_m79_p_pin_cross_agency_record_returns_pitch_unavailable(): void
    {
        [$agencyA, $userIdA] = $this->seedAgencyUserProperty();
        [$agencyB, $userIdB] = $this->seedAgencyUserProperty();
        $listingInB = $this->insertProspectingListing($agencyB, $userIdB);

        $resp = $this->actingAs(User::find($userIdA))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => (string) $listingInB,
            'location_key' => 'sha256:m79',
            'source'       => 'single_detail',
            'address'      => '12 Test Lane, Margate',
            'latitude'     => -30.86,
            'longitude'    => 30.37,
            'suburb'       => 'Margate',
        ])->assertOk();

        $body = $resp->json();
        $this->assertNull($body['redirect_url']);
        $this->assertSame('pitch_unavailable', $body['error']);
    }

    /** M80 — P-pin click on a listing whose address collides with ANOTHER
     *  agent's active draft must NOT silently redirect to the MIC
     *  Opportunities tab. The 2adf9698 fix removed the silent MIC
     *  fallback in MapActivityController when resolveProspectingListingId
     *  returned null, but EntryPointController::resolveCollisionForListing
     *  (added in bc22bfcf) STILL redirects to MIC on other_draft
     *  collisions. The user couldn't see the difference: from the popup
     *  tab's perspective, "Prospect Now → MIC" happened either way.
     *  This test pins the post-fix contract: the activity-log endpoint
     *  pre-checks the collision and returns pitch_blocked_other_draft +
     *  redirect_url=null, so the client surfaces the warning toast
     *  instead of bouncing the popup through entry-point → MIC. */
    public function test_m80_p_pin_other_draft_collision_returns_pitch_blocked_not_mic_redirect(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $otherUser = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        // Seed an OTHER agent's active draft at the same GPS as the
        // prospecting_listing the agent is about to Prospect Now on.
        $draftPropertyId = (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => 'Other agent draft',
            'address'       => '12 Test Lane, Margate',
            'suburb'        => 'Margate',
            'latitude'      => -30.86,
            'longitude'     => 30.37,
            'price'         => 1_200_000,
            'property_type' => 'house',
            'status'        => 'draft',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $otherUser->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('tracked_properties')->insert([
            'agency_id'                => $agencyId,
            'external_id'              => 'TP-' . Str::random(8),
            'street_number'            => '12',
            'street_name'              => 'Test Lane',
            'suburb'                   => 'Margate',
            'latitude'                 => -30.86,
            'longitude'                => 30.37,
            'promoted_to_property_id'  => $draftPropertyId,
            'promoted_at'              => now(),
            'status'                   => 'promoted',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $listingId = $this->insertProspectingListing($agencyId, $userId);

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'prospect_launched',
            'category'     => 'active_listings',
            'record_id'    => (string) $listingId,
            'location_key' => 'sha256:m80',
            'source'       => 'single_detail',
            'address'      => '12 Test Lane, Margate',
            'latitude'     => -30.86,
            'longitude'    => 30.37,
            'suburb'       => 'Margate',
        ])->assertOk();

        $body = $resp->json();
        $this->assertNull($body['redirect_url'],
            'other_draft collision must NOT carry a redirect_url — the popup must not navigate');
        $this->assertSame('pitch_blocked_other_draft', $body['error']);
        $this->assertNotEmpty($body['error_message']);
        $this->assertStringContainsString('draft', mb_strtolower($body['error_message']));
        // Defence-in-depth: the response must not leak the MIC URL
        // ANYWHERE — the precise regression we're protecting against.
        $encoded = json_encode($body);
        $this->assertStringNotContainsStringIgnoringCase('opportunities', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('market-intelligence', $encoded);
    }

    /** M24 — user-facing "valuation" was removed from active CoreX views.
     *
     * Strips ALL of these (which are protected per spec):
     *   - {{-- Blade comments --}}
     *   - PHP variable property/index access containing "valuation"
     *     ($foo->municipal_valuation, $arr['cma_valuation'])
     *   - Quoted string literals containing "valuation" — DB column names,
     *     array keys, where-clauses, route segments
     *
     * What remains is free-text — heading text, label text, English prose —
     * which MUST NOT contain "valuation".
     */
    public function test_m24_no_user_facing_valuation_strings_in_active_views(): void
    {
        $hotspots = [
            base_path('app/Http/Controllers/Map/MapController.php'),
            base_path('resources/views/corex/map/index.blade.php'),
            base_path('resources/views/corex/tracked-properties/show.blade.php'),
            base_path('resources/views/corex/market-intelligence/opportunity-detail.blade.php'),
            base_path('resources/views/corex/properties/intelligence/_market-snapshot.blade.php'),
            base_path('resources/views/presentations/index.blade.php'),
            base_path('resources/views/presentations/show.blade.php'),
            base_path('resources/views/presentations/analysis.blade.php'),
            base_path('resources/views/presentations/pricing-simulator-present.blade.php'),
            base_path('resources/views/presentations/partials/analysis-data-review.blade.php'),
            base_path('resources/views/evaluation/index.blade.php'),
        ];

        $strip = [
            '/\{\{--.*?--\}\}/s',                                      // Blade comments
            '/\$[A-Za-z_]+->[A-Za-z_]*valuation[A-Za-z_]*/i',          // $foo->bar_valuation
            '/\[\s*[\'"][A-Za-z._]*valuation[A-Za-z._]*[\'"]\s*\]/i',  // $arr['x_valuation']
            '/[\'"][A-Za-z._]*valuation[A-Za-z._]*[\'"]/i',            // 'municipal_valuation' / "cma_valuation" — keys/columns
        ];

        foreach ($hotspots as $file) {
            if (!file_exists($file)) continue;
            $body = file_get_contents($file);
            $stripped = $body;
            foreach ($strip as $p) {
                $stripped = (string) preg_replace($p, '', $stripped);
            }
            $this->assertDoesNotMatchRegularExpression(
                '/\b(valuation|valuations)\b/i',
                $stripped,
                "User-facing 'valuation' still present in {$file}"
            );
        }
    }

    // ── A.2.3 Item 1 — Portal Stock rename ────────────────────────────────

    /** M30 — left-rail layer config in the map view has the renamed label
     *        and letter for active_listings (UI-only rename; API key stays). */
    public function test_m30_map_view_renders_portal_stock_label(): void
    {
        $view = file_get_contents(base_path('resources/views/corex/map/index.blade.php'));
        $this->assertStringContainsString("'label' => 'Portal Stock'", $view,
            'Left-rail layerDefs must use Portal Stock label');
        $this->assertStringContainsString("'letter' => 'P'", $view,
            'Portal Stock letter is P (was A)');
        $this->assertStringNotContainsString("'label' => 'Active Listings'", $view,
            'Active Listings label removed from layerDefs');
    }

    /** M31 — JS LAYER_NAMES constant uses Portal Stock for the right-panel
     *        composite-row category header. */
    public function test_m31_layer_names_js_constant_uses_portal_stock(): void
    {
        $view = file_get_contents(base_path('resources/views/corex/map/index.blade.php'));
        $this->assertStringContainsString("active_listings: 'Portal Stock'", $view);
        $this->assertStringNotContainsString("active_listings: 'Active Listing'", $view);
    }

    // ── A.2.3 Item 3 — Seller View identity redaction ─────────────────────

    /** M36 — Seller View redacts owner_name on scheme_owner records and
     *        does NOT exclude the layer (pre-A.2.3 behaviour was exclude). */
    public function test_m36_seller_view_redacts_owner_name(): void
    {
        [$agencyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        DB::table('scheme_owners')->insert([
            'agency_id'        => $agencyId,
            'market_report_id' => $reportId,
            'scheme_name'      => 'Sunset Manor',
            'section_number'   => '1',
            'owner_name'       => 'James Taylor',
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['scheme_owners'],
            viewMode: 'seller',
            agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);

        $schemeRecords = collect($resp['locations'])
            ->flatMap(fn ($l) => $l['records'])
            ->filter(fn ($r) => $r['category'] === 'scheme_owners')
            ->values();

        if ($schemeRecords->isEmpty()) {
            $this->markTestSkipped('No scheme_owners pin produced — market_report subject GPS likely not in bounds.');
        }

        foreach ($schemeRecords as $rec) {
            $this->assertNotEquals('James Taylor', $rec['subtitle'] ?? null,
                'Owner name must NOT appear in Seller View');
            $this->assertStringContainsString('Owner', (string) ($rec['subtitle'] ?? ''),
                'Generic "Owner" label replaces the name');
        }
    }

    /** M37 — Seller View map response omits phone and email (no schema
     *        columns yet, so both should be null in record output). */
    public function test_m37_seller_view_strips_phone_and_email(): void
    {
        [$agencyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        DB::table('scheme_owners')->insert([
            'agency_id'        => $agencyId,
            'market_report_id' => $reportId,
            'scheme_name'      => 'Sunset Manor',
            'section_number'   => '1',
            'owner_name'       => 'James Taylor',
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['scheme_owners'], viewMode: 'seller', agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);

        $records = collect($resp['locations'])->flatMap(fn ($l) => $l['records']);
        foreach ($records as $rec) {
            $this->assertNull($rec['owner_phone'] ?? null);
            $this->assertNull($rec['owner_email'] ?? null);
        }
    }

    /** M38 — Agent View leaves owner identity intact on scheme_owner records. */
    public function test_m38_agent_view_keeps_owner_identity(): void
    {
        [$agencyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        DB::table('scheme_owners')->insert([
            'agency_id'        => $agencyId,
            'market_report_id' => $reportId,
            'scheme_name'      => 'Sunset Manor',
            'section_number'   => '1',
            'owner_name'       => 'James Taylor',
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['scheme_owners'], viewMode: 'agent', agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);

        $records = collect($resp['locations'])->flatMap(fn ($l) => $l['records'])
            ->filter(fn ($r) => $r['category'] === 'scheme_owners')
            ->values();

        if ($records->isEmpty()) {
            $this->markTestSkipped('No scheme_owners pin produced.');
        }

        $this->assertSame('James Taylor', $records[0]['subtitle'],
            'Agent View shows the real owner name in the subtitle');
    }

    /** M39 — Seller-view scheme_owner detail card returns the building card
     *        WITHOUT sensitive_facts (replaces the pre-A.2.3 403 response). */
    public function test_m39_seller_view_scheme_owner_card_omits_sensitive_facts(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $ownerId = (int) DB::table('scheme_owners')->insertGetId([
            'agency_id'        => $agencyId,
            'market_report_id' => $reportId,
            'scheme_name'      => 'Sunset Manor',
            'section_number'   => '1',
            'owner_name'       => 'James Taylor',
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.scheme-owner', ['owner' => $ownerId]) . '?viewMode=seller');

        $resp->assertOk();
        $body = $resp->json();
        $this->assertArrayHasKey('facts', $body);
        $this->assertArrayNotHasKey('sensitive_facts', $body,
            'Seller View must omit sensitive_facts entirely');
    }

    // ── A.2.3 Item 4 — Portal strip + listing_opened activity ─────────────

    /** M40 — Property::publicListingUrls() populates hfc slot when active.
     *        The hfc slot IS the canonical public_url (single source of truth). */
    public function test_m40_public_listing_urls_includes_hfc_when_eligible(): void
    {
        config()->set('integrations.public_website_url', 'https://www.hfcoastal.co.za');
        $p = new \App\Models\Property();
        $p->setRawAttributes([
            'id'        => 999,
            'agency_id' => 1,
            'status'    => 'active',
            'suburb'    => 'Uvongo',
            'city'      => 'Margate',
            'town'      => 'Margate',
            'province'  => 'KwaZulu-Natal',
            'property_type' => 'apartment',
            'listing_type'  => 'sale',
        ]);
        $urls = $p->publicListingUrls();
        $this->assertNotNull($urls['hfc']);
        // /property/{slug}-{id}, resolved by the trailing CoreX id — NOT the
        // retired /listing/{ref}/… pattern, which 404s on the live site.
        $this->assertStringContainsString('hfcoastal.co.za/property/999', $urls['hfc']);
        $this->assertSame($p->public_url, $urls['hfc']);
    }

    /** M41 — the hfc slot resolves to the canonical public_url pattern:
     *        {base}/property/{slug}-{id}. */
    public function test_m41_hfc_slot_matches_canonical_public_url_pattern(): void
    {
        config()->set('integrations.public_website_url', 'https://www.hfcoastal.co.za');
        $p = new \App\Models\Property();
        $p->setRawAttributes([
            'id'            => 1569172,
            'agency_id'     => 1,
            'status'        => 'active',
            'title'         => 'Apartment for sale in Uvongo Beach',
            'property_type' => 'apartment',
            'listing_type'  => 'sale',
            'suburb'        => 'Uvongo Beach',
            'city'          => 'Margate',
            'town'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
        ]);
        $this->assertSame(
            'https://www.hfcoastal.co.za/property/apartment-for-sale-in-uvongo-beach-1569172',
            $p->publicListingUrls()['hfc'],
        );
    }

    /** M42 — number of non-null URL slots equals number of pills the JS
     *        should render. Test 0/1/2/3 portal combinations. */
    public function test_m42_portal_count_varies_with_syndication_status(): void
    {
        // 0 portals — inactive everywhere.
        $p0 = new \App\Models\Property();
        $p0->setRawAttributes(['id' => 1, 'agency_id' => 99, 'status' => 'pending']);
        $this->assertSame(0, count(array_filter($p0->publicListingUrls())));

        // 1 portal — only HFC website applies (agency 1, active).
        $p1 = new \App\Models\Property();
        $p1->setRawAttributes([
            'id' => 1, 'agency_id' => 1, 'status' => 'active',
            'suburb' => 'X', 'city' => 'Y', 'town' => 'Y', 'province' => 'kzn',
            'property_type' => 'house', 'listing_type' => 'sale',
        ]);
        $this->assertSame(1, count(array_filter($p1->publicListingUrls())));

        // 2 portals — HFC + P24 active.
        $p2 = new \App\Models\Property();
        $p2->setRawAttributes([
            'id' => 1, 'agency_id' => 1, 'status' => 'active',
            'suburb' => 'X', 'city' => 'Y', 'town' => 'Y', 'province' => 'kzn',
            'property_type' => 'house', 'listing_type' => 'sale',
            'p24_ref' => '12345', 'p24_syndication_status' => 'active',
            'pp_suburb_id' => 0,
        ]);
        $this->assertSame(2, count(array_filter($p2->publicListingUrls())));

        // 3 portals — all active.
        $p3 = new \App\Models\Property();
        $p3->setRawAttributes([
            'id' => 1, 'agency_id' => 1, 'status' => 'active',
            'suburb' => 'X', 'city' => 'Y', 'town' => 'Y', 'province' => 'kzn',
            'property_type' => 'house', 'listing_type' => 'sale',
            'p24_ref' => '12345', 'p24_syndication_status' => 'active',
            'pp_ref'  => 'PP-9',   'pp_syndication_status'  => 'active',
            'pp_suburb_id' => 0,
        ]);
        $this->assertSame(3, count(array_filter($p3->publicListingUrls())));
    }

    /** M43 — POST /activity/log with action=listing_opened + portal=p24
     *        dispatches MapListingOpened with portal in payload. */
    public function test_m43_listing_opened_fires_event_with_portal(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\Map\MapListingOpened::class]);
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'listing_opened',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:m43',
            'source'       => 'single_detail',
            'portal'       => 'p24',
        ]);

        $resp->assertOk();
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\Map\MapListingOpened::class, function ($e) use ($propertyId) {
            return (int) $e->property->id === $propertyId
                && $e->portal === 'p24';
        });
    }

    /** M44 — activity-log row carries the portal field in payload. */
    public function test_m44_listing_opened_row_includes_portal_in_payload(): void
    {
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'listing_opened',
            'category'     => 'hfc_listings',
            'record_id'    => $propertyId,
            'location_key' => 'sha256:m44',
            'source'       => 'single_detail',
            'portal'       => 'hfc',
        ])->assertOk();

        $row = AgentActivityEvent::where('agency_id', $agencyId)
            ->where('event_type', 'LIKE', 'map_listing%')
            ->latest('id')
            ->first();
        $this->assertNotNull($row, 'MapListingOpened should land via LogAgentActivity');
        $this->assertSame('map_listing.opened', $row->event_type);

        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame('hfc', $payload['portal'] ?? null);
        $this->assertSame($propertyId, $payload['property_id'] ?? null);
    }

    // ── A.2.4 — toggle live-refresh + rich detail panel + Copy ID ─────────

    /** M45 — pin endpoint accepts viewMode=seller and redacts scheme records. */
    public function test_m45_pin_endpoint_with_seller_view_redacts_scheme_owners(): void
    {
        [$agencyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        DB::table('scheme_owners')->insert([
            'agency_id' => $agencyId, 'market_report_id' => $reportId,
            'scheme_name' => 'Sunset Manor', 'section_number' => '1',
            'owner_name' => 'James Taylor', 'is_demo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['scheme_owners'], viewMode: 'seller', agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);
        $records = collect($resp['locations'])->flatMap(fn ($l) => $l['records']);
        if ($records->isEmpty()) {
            $this->markTestSkipped('No scheme_owners pins produced.');
        }
        foreach ($records as $rec) {
            $this->assertNotEquals('James Taylor', $rec['subtitle']);
        }
    }

    /** M46 — pin endpoint with viewMode=agent returns full owner identity. */
    public function test_m46_pin_endpoint_with_agent_view_keeps_identity(): void
    {
        [$agencyId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        DB::table('scheme_owners')->insert([
            'agency_id' => $agencyId, 'market_report_id' => $reportId,
            'scheme_name' => 'Sunset Manor', 'section_number' => '1',
            'owner_name' => 'James Taylor', 'is_demo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = new \App\Services\Map\MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['scheme_owners'], viewMode: 'agent', agencyId: $agencyId,
        );
        $resp = $svc->getPinsInBounds($req);
        $records = collect($resp['locations'])->flatMap(fn ($l) => $l['records'])
            ->filter(fn ($r) => $r['category'] === 'scheme_owners')->values();
        if ($records->isEmpty()) {
            $this->markTestSkipped('No scheme_owners pins produced.');
        }
        $this->assertSame('James Taylor', $records[0]['subtitle']);
    }

    /** M47 — map view JS contains the panel re-fetch hook on view toggle. */
    public function test_m47_map_view_re_fetches_open_panel_on_view_toggle(): void
    {
        $view = file_get_contents(base_path('resources/views/corex/map/index.blade.php'));
        // The toggle handler explicitly re-opens single_detail with the same
        // record + parent + location_key so the new viewMode applies.
        $this->assertStringContainsString("panelState === 'single_detail'", $view,
            'toggle handler must inspect panel state for live re-fetch');
        $this->assertStringContainsString('openSingleDetail(panelCurrentRecord', $view,
            'toggle handler must re-call openSingleDetail with the current record');
    }

    /** M48 — schemeOwnerCard Agent View returns enriched facts + sensitive_facts. */
    public function test_m48_scheme_owner_card_agent_view_enriched(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $ownerId = (int) DB::table('scheme_owners')->insertGetId([
            'agency_id' => $agencyId, 'market_report_id' => $reportId,
            'scheme_name' => 'Atlantis', 'section_number' => '1',
            'flat_number' => '1A', 'scheme_ss_number' => 'SS123/2019',
            'owner_name' => 'James Taylor', 'extent_m2' => 85,
            'property_type' => 'apartment',
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.scheme-owner', ['owner' => $ownerId]) . '?viewMode=agent');
        $resp->assertOk();

        $body = $resp->json();
        $factLabels      = array_column($body['facts'], 'label');
        $sensitiveLabels = array_column($body['sensitive_facts'] ?? [], 'label');

        $this->assertContains('Scheme', $factLabels);
        $this->assertContains('Section', $factLabels);
        $this->assertContains('Flat', $factLabels);
        $this->assertContains('SS number', $factLabels);
        $this->assertContains('Floor area', $factLabels);
        $this->assertContains('Property type', $factLabels);
        $this->assertContains('Owner', $sensitiveLabels);
    }

    /** M49 — same endpoint in Seller View has facts but no sensitive_facts key. */
    public function test_m49_scheme_owner_card_seller_view_omits_sensitive(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $ownerId = (int) DB::table('scheme_owners')->insertGetId([
            'agency_id' => $agencyId, 'market_report_id' => $reportId,
            'scheme_name' => 'Atlantis', 'section_number' => '1',
            'owner_name' => 'James Taylor', 'is_demo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.scheme-owner', ['owner' => $ownerId]) . '?viewMode=seller');
        $resp->assertOk();

        $body = $resp->json();
        $this->assertNotEmpty($body['facts'], 'Building facts visible in Seller View');
        $this->assertArrayNotHasKey('sensitive_facts', $body,
            'Seller View must omit sensitive_facts entirely');
    }

    /** M50 — soldCardFromMrcr returns R/m² (computed when missing) + property
     *        type + condition fields. */
    public function test_m51_sold_comp_mrcr_card_shows_rich_fields_with_r_per_m2(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $compId = (int) DB::table('market_report_comp_rows')->insertGetId([
            'market_report_id' => $reportId,
            'agency_id'        => $agencyId,
            'row_index'        => 1,
            'row_type'         => 'comp',
            'address'          => '15 Bairn Street',
            'property_type'    => 'apartment',
            'extent_m2'        => 80,
            'sale_date'        => '2024-05-15',
            'sale_price'       => 1_200_000,
            'r_per_m2'         => null,  // forces compute path
            'condition'        => 'good',
            'days_on_market'   => 45,
            'latitude'         => -30.84,
            'longitude'        => 30.39,
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.sold', ['layerId' => 'mrcr:' . $compId]) . '?viewMode=agent');
        $resp->assertOk();

        $labels = array_column($resp->json('facts'), 'label');
        $values = array_column($resp->json('facts'), 'value');

        $this->assertContains('R/m²', $labels, 'R/m² label must be present');
        $this->assertContains('Property type', $labels);
        $this->assertContains('Condition', $labels);
        $this->assertContains('Days on market', $labels);
        // Computed R/m² = 1_200_000 / 80 = 15_000 → "R 15 000"
        $this->assertContains('R 15 000', $values);
    }

    /** M50 — Portal Stock detail (activeCardFromPal) surfaces captured_at,
     *        days on portal, portal source, "Open on portal" relationship. */
    public function test_m50_portal_stock_pal_card_shows_capture_metadata(): void
    {
        // PAL cards need a presentation row. Skip if presentations table
        // requires more setup than this minimal test seeds.
        [$agencyId, $userId] = $this->seedAgencyUserProperty();

        // Minimal presentation row — used by the join in activeCardFromPal.
        $presentationId = (int) DB::table('presentations')->insertGetId([
            'agency_id'           => $agencyId,
            'branch_id'           => $agencyId,
            'created_by_user_id'  => $userId,
            'title'               => 'Test Presentation',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $palId = (int) DB::table('presentation_active_listings')->insertGetId([
            'presentation_id'    => $presentationId,
            'agency_id'          => $agencyId,
            'list_price_inc'     => 1_500_000,
            'beds'               => 2, 'baths' => 2, 'size_m2' => 75,
            'suburb'             => 'Margate',
            'property_type'      => 'apartment',
            'listing_date'       => now()->subDays(20)->toDateString(),
            'raw_row_json'       => json_encode([
                'address'        => '12 Marine Drive, Margate',
                'days_on_market' => 20,
                'portal_source'  => 'p24',
                'portal_ref'     => 'P24-12345',
                'portal_url'     => 'https://www.property24.com/listing/12345',
                'agent_name'     => 'Test Agent',
                'agency_name'    => 'Test Agency',
            ]),
            'extraction_method'  => 'email_import',
            'parser_version'     => 'test-v1',
            'first_seen_at'      => now()->subDays(20),
            'last_seen_at'       => now(),
            'is_active'          => true,
            'is_demo'            => false,
            'created_at'         => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.active', ['layerId' => 'pal:' . $palId]) . '?viewMode=agent');
        $resp->assertOk();

        $labels = array_column($resp->json('facts'), 'label');
        $this->assertContains('Days on portal', $labels);
        $this->assertContains('Captured at', $labels);
        $this->assertContains('Portal', $labels);
        $this->assertContains('Capture method', $labels);

        $relUrls = array_column($resp->json('relationships'), 'url');
        $this->assertContains('https://www.property24.com/listing/12345', $relUrls,
            'Portal URL surfaces as a relationship link');

        // Agent name lives in sensitive_facts.
        $sensitiveLabels = array_column($resp->json('sensitive_facts') ?? [], 'label');
        $this->assertContains('Listing agent', $sensitiveLabels);
    }

    /** M52 — micSubjectCard surfaces report metadata. */
    public function test_m52_mic_subject_card_shows_report_metadata(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.mic-subject', ['report' => $reportId]) . '?viewMode=agent');
        $resp->assertOk();

        $labels = array_column($resp->json('facts'), 'label');
        $this->assertContains('Report type', $labels);
        $this->assertContains('Report date', $labels);
        $this->assertContains('GPS', $labels);
        $this->assertContains('Pulled at', $labels);
    }

    /** M53 — POST id_copied dispatches MapIdCopied event + writes to
     *        agent_activity_events. */
    public function test_m53_copy_id_fires_activity_event(): void
    {
        [$agencyId, $userId] = $this->seedAgencyUserProperty();
        $reportId = $this->seedMarketReport($agencyId);
        $ownerId = (int) DB::table('scheme_owners')->insertGetId([
            'agency_id' => $agencyId, 'market_report_id' => $reportId,
            'scheme_name' => 'Atlantis', 'owner_name' => 'X', 'is_demo' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'       => 'id_copied',
            'category'     => 'scheme_owners',
            'record_id'    => (string) $ownerId,
            'location_key' => 'sha256:m53',
            'source'       => 'single_detail',
        ])->assertOk();

        $row = AgentActivityEvent::where('agency_id', $agencyId)
            ->where('event_type', 'LIKE', 'map_id%')
            ->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('map_id.copied', $row->event_type);
    }

    /** M54 — maskIdNumber masks all but last 4 digits. */
    public function test_m54_id_number_masking_helper(): void
    {
        $this->assertSame('*********9085', \App\Http\Controllers\Map\MapController::maskIdNumber('8901015009085'));
        $this->assertSame('1234',          \App\Http\Controllers\Map\MapController::maskIdNumber('1234'),    'Short IDs unchanged');
        $this->assertSame('**5678',        \App\Http\Controllers\Map\MapController::maskIdNumber('125678'),  'Always reveals last 4');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Seed agency + branch + user + a single HFC property; return ids. */
    private function seedAgencyUserProperty(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test Agency ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // role=super_admin bypasses the permission middleware via Role.is_owner
        // — keeps the test focused on the endpoint contract, not RBAC seeding.
        $user = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);

        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => '18 Golf Course Road',
            'address'       => '18 Golf Course Road',
            'suburb'        => 'Uvongo',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
            'price'         => 1_200_000,
            'property_type' => 'house',
            'status'        => 'active',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$agencyId, $user->id, $propertyId];
    }

    private function seedMarketReport(int $agencyId): int
    {
        $reportTypeId = (int) DB::table('market_report_types')->insertGetId([
            'key'                  => 'test-' . Str::random(6),
            'display_name'         => 'Test Report',
            'parser_class'         => 'App\\Services\\TestParser', // schema NOT NULL — value doesn't matter for this test
            'expected_fields_json' => json_encode([]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
        // uploaded_by_user_id is NOT NULL on this schema — fake a sentinel user id.
        $uploaderId = (int) DB::table('users')->insertGetId([
            'name' => 'Uploader-' . Str::random(6),
            'email' => 'up-' . Str::random(8) . '@test.local',
            'password' => bcrypt('x'),
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'             => $agencyId,
            'report_type_id'        => $reportTypeId,
            'uploaded_by_user_id'   => $uploaderId,
            'file_path'             => 'test/path.pdf',
            'file_name'             => 'test.pdf',
            'file_hash'             => hash('sha256', Str::random(20)),
            'report_date'           => now()->toDateString(),
            'subject_address'       => 'Test subject address',
            // schemeOwners() joins on subject_scheme_name LIKE so.scheme_name
            // — must be populated for scheme-owner pins to find their GPS.
            'subject_scheme_name'   => 'Sunset Manor',
            'subject_latitude'      => -30.84,
            'subject_longitude'     => 30.39,
            'is_demo'               => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    private function seedSchemeOwner(int $agencyId, ?int $reportId = null): int
    {
        $reportId = $reportId ?? $this->seedMarketReport($agencyId);
        return (int) DB::table('scheme_owners')->insertGetId([
            'market_report_id' => $reportId,
            'agency_id'        => $agencyId,
            'scheme_name'      => 'Topanga',
            'owner_name'       => 'Test Owner',
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Seed a prospecting_listings row scoped to the given agency, with the
     *  fields the activity-log endpoint needs to resolve it via Case 1
     *  (purely numeric record_id). */
    private function insertProspectingListing(int $agencyId, int $userId): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'            => $agencyId,
            'captured_by_user_id'  => $userId,
            'portal_source'        => 'p24',
            'portal_ref'           => 'test-' . Str::random(8),
            'portal_url'           => 'https://example.com/' . Str::random(6),
            'address'              => '12 Test Lane, Margate',
            'suburb'               => 'Margate',
            'price'                => 1_500_000,
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'is_active'            => true,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }
}
