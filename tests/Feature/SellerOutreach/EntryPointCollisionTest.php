<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Events\Map\MapProspectLaunched;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.3.4 — MIC entry-point collision detection tests (M92-M96).
 *
 * Exercises the EntryPointController::fromProspecting() choke point that
 * catches R5/R6 PITCH NOW chips + MIC slideover out-of-stock Pitch + legacy
 * /prospecting rows in one shot. Same 6-state collision logic the map's
 * Portal Stock Prospect Now uses (via MapProspectStatusService::resolve).
 */
final class EntryPointCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** M92 — available status proceeds to contact-capture view normally. */
    public function test_m92_available_status_renders_contact_capture(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        // No Property or TP seeded — resolver returns 'available'.
        $listingId = $this->seedProspectingListing($agencyId, [
            'address' => '99 Brand New Street',
            'suburb'  => 'Margate',
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]));

        $resp->assertOk();
        $resp->assertViewIs('seller-outreach.entry.prospecting-create-contact');
    }

    /** M93 — held status redirects to corex.properties.show with warning flash. */
    public function test_m93_held_status_redirects_to_property_show(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, 'active',
            address: '18 Golf Course Road', lat: -30.84, lng: 30.39);
        $tpId = $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.84, lng: 30.39, streetNumber: '18', streetName: 'Golf Course Road', suburb: 'Uvongo');
        $listingId = $this->seedProspectingListing($agencyId, [
            'address'             => '18 Golf Course Road',
            'suburb'              => 'Uvongo',
            'tracked_property_id' => $tpId,
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]));

        $resp->assertRedirect(route('corex.properties.show', ['property' => $propertyId]));
        $this->assertStringContainsString(
            "already on HFC's books",
            (string) session('warning'),
            'held status must surface the "already on HFC books" warning'
        );
    }

    /** M94 — other_draft blocks and redirects to MIC work tab with coordination msg. */
    public function test_m94_other_draft_blocks_with_coordination_message(): void
    {
        [$agencyId, $aliceId] = $this->seedAgency();
        $bob = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'role'      => 'agent', 'name' => 'Bob Builder',
        ]);

        $propertyId = $this->seedProperty($agencyId, $bob->id, 'draft',
            address: '7 Drafty Lane', lat: -30.85, lng: 30.40);
        $tpId = $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.85, lng: 30.40, streetNumber: '7', streetName: 'Drafty Lane', suburb: 'Uvongo');
        $listingId = $this->seedProspectingListing($agencyId, [
            'address'             => '7 Drafty Lane',
            'suburb'              => 'Uvongo',
            'tracked_property_id' => $tpId,
        ]);

        $resp = $this->actingAs(User::find($aliceId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]));

        $resp->assertRedirect(route('market-intelligence.work'));
        $msg = (string) session('warning');
        $this->assertStringContainsString('Bob Builder', $msg);
        $this->assertStringContainsString('Coordinate', $msg);
    }

    /** M95 — previously_sold proceeds with warning flash + renders contact-capture. */
    public function test_m95_previously_sold_proceeds_with_warning(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, $userId, 'sold',
            address: '14 Memory Lane', lat: -30.86, lng: 30.41);
        $tpId = $this->seedTrackedProperty($agencyId, promotedTo: $propertyId,
            lat: -30.86, lng: 30.41, streetNumber: '14', streetName: 'Memory Lane', suburb: 'Uvongo');
        $listingId = $this->seedProspectingListing($agencyId, [
            'address'             => '14 Memory Lane',
            'suburb'              => 'Uvongo',
            'tracked_property_id' => $tpId,
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]));

        $resp->assertOk();
        $resp->assertViewIs('seller-outreach.entry.prospecting-create-contact');
        $msg = (string) session('warning');
        $this->assertStringContainsString('Previously sold', $msg);
    }

    /** M96 — MapProspectLaunched fires with source='mic_entry_point' on available
     *        status when the listing has a tracked_property_id link. */
    public function test_m96_mic_entry_point_event_fires_on_proceed(): void
    {
        Event::fake([MapProspectLaunched::class]);
        [$agencyId, $userId] = $this->seedAgency();

        $tpId = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id'     => $agencyId,
            'external_id'   => 'TP-' . Str::random(8),
            'street_number' => '21',
            'street_name'   => 'New Street',
            'suburb'        => 'Margate',
            'latitude'      => -30.88,
            'longitude'     => 30.38,
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $listingId = $this->seedProspectingListing($agencyId, [
            'address'             => '21 New Street',
            'suburb'              => 'Margate',
            'tracked_property_id' => $tpId,
        ]);

        $this->actingAs(User::find($userId))
            ->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]))
            ->assertOk();

        Event::assertDispatched(MapProspectLaunched::class, function ($e) use ($tpId) {
            return (int) $e->trackedProperty->id === $tpId
                && $e->source === 'mic_entry_point';
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
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

    private function seedProperty(
        int $agencyId, int $userId, string $status,
        string $address = '18 Golf Course Road', float $lat = -30.84, float $lng = 30.39,
    ): int {
        return (int) DB::table('properties')->insertGetId([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => $address,
            'address'       => $address,
            'suburb'        => 'Uvongo',
            'latitude'      => $lat,
            'longitude'     => $lng,
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

    private function seedProspectingListing(int $agencyId, array $overrides): int
    {
        // captured_by_user_id is NOT NULL; default to the agency's first user
        // when caller didn't pass one explicitly.
        $defaultCapturedBy = (int) DB::table('users')
            ->where('agency_id', $agencyId)
            ->orderBy('id')
            ->value('id');

        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id'           => $agencyId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $defaultCapturedBy,
            'is_active'           => true,
            'address'             => '99 Unknown',
            'suburb'              => 'Unknown',
            'price'               => 0,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));
    }

    private function seedTrackedProperty(
        int $agencyId, int $promotedTo, float $lat, float $lng,
        string $streetNumber, string $streetName, string $suburb,
    ): int {
        return (int) DB::table('tracked_properties')->insertGetId([
            'agency_id'              => $agencyId,
            'external_id'            => 'TP-' . Str::random(8),
            'street_number'          => $streetNumber,
            'street_name'            => $streetName,
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
}
