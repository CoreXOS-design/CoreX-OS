<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\AgentActivityEvent;
use App\Models\User;
use App\Services\Map\MapProspectStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.2.5 — prospect-collision detector tests M55-M61.
 */
final class ProspectStatusTest extends TestCase
{
    use RefreshDatabase;

    /** M55 — no HFC history → status=available. */
    public function test_m55_status_available_when_no_match(): void
    {
        [$agencyId, $userId] = $this->seedAgency();

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '99 Nowhere Lane',
            'latitude' => -30.5, 'longitude' => 30.5, 'suburb' => 'Nowhere',
        ], $agencyId, $userId);

        $this->assertSame('available', $status['status']);
    }

    /** M56 — active property → status=held. */
    public function test_m56_status_held_for_active_property(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, status: 'active');
        $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.84, lng: 30.39, address: '18 Golf Course Road', suburb: 'Uvongo');

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '18 Golf Course Road', 'latitude' => -30.84, 'longitude' => 30.39, 'suburb' => 'Uvongo',
        ], $agencyId, $userId);

        $this->assertSame('held', $status['status']);
        $this->assertSame($propertyId, $status['property_id']);
    }

    /** M57 — draft owned by current user → own_draft; by another user → other_draft. */
    public function test_m57_status_own_draft_vs_other_draft(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $otherUser = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        // own_draft
        $ownPropertyId = $this->seedProperty($agencyId, $userId, status: 'draft');
        $this->seedTrackedProperty($agencyId, promotedTo: $ownPropertyId,
            lat: -30.84, lng: 30.39, address: '18 Golf Course Road', suburb: 'Uvongo');

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '18 Golf Course Road', 'latitude' => -30.84, 'longitude' => 30.39, 'suburb' => 'Uvongo',
        ], $agencyId, $userId);
        $this->assertSame('own_draft', $status['status']);
        $this->assertSame($ownPropertyId, $status['property_id']);

        // other_draft — same agency, different agent.
        $otherPropertyId = $this->seedProperty($agencyId, $otherUser->id, status: 'draft');
        $this->seedTrackedProperty($agencyId, promotedTo: $otherPropertyId,
            lat: -30.85, lng: 30.40, address: '12 Bairn Street', suburb: 'Uvongo');

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '12 Bairn Street', 'latitude' => -30.85, 'longitude' => 30.40, 'suburb' => 'Uvongo',
        ], $agencyId, $userId);
        $this->assertSame('other_draft', $status['status']);
        $this->assertSame($otherPropertyId, $status['property_id']);
        $this->assertSame($otherUser->name, $status['agent_name']);
    }

    /** M58 — no `mandates` table in this codebase → previously_held is not
     *        currently emitted. M58 documents that gap: a sold property is
     *        currently emitted as `previously_sold`, not `previously_held`.
     *        This test pins the documented behaviour. */
    public function test_m58_previously_held_status_not_emitted_today(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, status: 'sold');
        $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.84, lng: 30.39, address: '18 Golf Course Road', suburb: 'Uvongo');

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '18 Golf Course Road', 'latitude' => -30.84, 'longitude' => 30.39, 'suburb' => 'Uvongo',
        ], $agencyId, $userId);

        $this->assertNotSame('previously_held', $status['status'],
            'previously_held requires a mandate-end column the schema does not have');
        $this->assertSame('previously_sold', $status['status']);
    }

    /** M59 — sold property → previously_sold. */
    public function test_m59_status_previously_sold_for_sold_property(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, status: 'sold');
        $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.84, lng: 30.39, address: '18 Golf Course Road', suburb: 'Uvongo');

        $status = app(MapProspectStatusService::class)->resolve([
            'address' => '18 Golf Course Road', 'latitude' => -30.84, 'longitude' => 30.39, 'suburb' => 'Uvongo',
        ], $agencyId, $userId);

        $this->assertSame('previously_sold', $status['status']);
        $this->assertSame($propertyId, $status['property_id']);
    }

    /** M60 — Portal Stock detail endpoint includes prospect_status in response.
     *        Tested via activeCardFromMrcr — the mrcr layer prefix. */
    public function test_m60_portal_stock_detail_includes_prospect_status(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        // Seed a MarketReport + MRCR row_type=listing the endpoint can find.
        $reportId = $this->seedMarketReport($agencyId, $userId);
        $compRowId = (int) DB::table('market_report_comp_rows')->insertGetId([
            'market_report_id' => $reportId,
            'agency_id'        => $agencyId,
            'row_index'        => 1,
            'row_type'         => 'listing',
            'address'          => '101 Test Road',
            'property_type'    => 'apartment',
            'list_price'       => 1_200_000,
            'latitude'         => -30.84,
            'longitude'        => 30.39,
            'is_demo'          => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->getJson(route('corex.map.active', ['layerId' => 'mrcr:' . $compRowId]) . '?viewMode=agent');

        $resp->assertOk();
        $this->assertArrayHasKey('prospect_status', $resp->json());
        $this->assertSame('available', $resp->json('prospect_status.status'),
            'No HFC property exists for this address → available');
    }

    /** M61 — Override action requires reason ≥ 20 chars + fires MapProspectOverride. */
    public function test_m61_override_requires_reason_and_fires_event(): void
    {
        Event::fake([\App\Events\Map\MapProspectOverride::class]);
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, status: 'draft');

        // Reason too short → 422
        $short = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'          => 'prospect_override',
            'category'        => 'active_listings',
            'record_id'       => 'mrcr:42',
            'location_key'    => 'sha256:m61',
            'source'          => 'single_detail',
            'property_id'     => $propertyId,
            'override_reason' => 'too short',
        ]);
        $short->assertStatus(422);
        Event::assertNotDispatched(\App\Events\Map\MapProspectOverride::class);

        // Valid reason ≥ 20 chars → 200 + event dispatched.
        $ok = $this->actingAs(User::find($userId))->postJson(route('corex.map.activity.log'), [
            'action'          => 'prospect_override',
            'category'        => 'active_listings',
            'record_id'       => 'mrcr:42',
            'location_key'    => 'sha256:m61',
            'source'          => 'single_detail',
            'property_id'     => $propertyId,
            'override_reason' => 'Seller called me directly and asked me to handle this listing.',
        ]);
        $ok->assertOk();
        Event::assertDispatched(\App\Events\Map\MapProspectOverride::class, function ($e) use ($propertyId) {
            return (int) $e->property->id === $propertyId
                && str_starts_with($e->reason, 'Seller called me directly');
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedProperty(int $agencyId, int $userId, string $status): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => 'Test Property',
            'address'       => '18 Golf Course Road',
            'suburb'        => 'Uvongo',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
            'price'         => 1_200_000,
            'property_type' => 'house',
            'status'        => $status,
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $userId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function seedTrackedProperty(
        int $agencyId, int $promotedTo, float $lat, float $lng, string $address, string $suburb,
    ): int {
        return (int) DB::table('tracked_properties')->insertGetId([
            'agency_id'              => $agencyId,
            'external_id'            => 'TP-' . Str::random(8),
            'street_number'          => preg_replace('/\D+/', '', explode(' ', $address)[0] ?? '0') ?: '0',
            'street_name'            => trim(preg_replace('/^\S+\s*/', '', $address)),
            'suburb'                 => $suburb,
            'latitude'               => $lat,
            'longitude'              => $lng,
            'promoted_to_property_id'=> $promotedTo,
            'promoted_at'            => now(),
            'status'                 => 'promoted',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    private function seedMarketReport(int $agencyId, int $userId): int
    {
        $reportTypeId = (int) DB::table('market_report_types')->insertGetId([
            'key' => 'test-' . Str::random(6),
            'display_name' => 'Test',
            'parser_class' => 'App\\Services\\TestParser',
            'expected_fields_json' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $agencyId,
            'report_type_id'      => $reportTypeId,
            'uploaded_by_user_id' => $userId,
            'file_path'           => 'test/path.pdf',
            'file_name'           => 'test.pdf',
            'file_hash'           => hash('sha256', Str::random(20)),
            'report_date'         => now()->toDateString(),
            'subject_address'     => 'Test subject',
            'subject_latitude'    => -30.84,
            'subject_longitude'   => 30.39,
            'is_demo'             => false,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}
