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
        $this->assertStringContainsString('opportunities', (string) $body['redirect_url']);

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
            'agency_id'           => $agencyId,
            'report_type_id'      => $reportTypeId,
            'uploaded_by_user_id' => $uploaderId,
            'file_path'           => 'test/path.pdf',
            'file_name'           => 'test.pdf',
            'file_hash'           => hash('sha256', Str::random(20)),
            'report_date'         => now()->toDateString(),
            'subject_address'     => 'Test subject address',
            'subject_latitude'    => -30.84,
            'subject_longitude'   => 30.39,
            'is_demo'             => false,
            'created_at'          => now(),
            'updated_at'          => now(),
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
}
