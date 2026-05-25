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
