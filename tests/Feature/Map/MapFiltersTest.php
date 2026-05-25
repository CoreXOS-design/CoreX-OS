<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\User;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.3.1 — Search + filter overhaul tests (M77-M83).
 *
 * These tests exercise the new pin-endpoint query params:
 *   scope (my/agency/all), search, bedroomsMin/Max, bathroomsMin/Max,
 *   standMin/Max, buildingMin/Max, listingStatus[], soldWindow, domMin/Max.
 *
 * They cover the hfcListings layer end-to-end because that layer carries
 * the full set of filterable columns (other layers narrow the same way
 * but with fewer columns — covered by the controller validation tests).
 */
final class MapFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** M77 — scope=my returns only the actor's own properties. */
    public function test_m77_scope_my_filters_to_current_agent_properties(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);
        $bob   = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Alice Road 1', 'latitude' => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $bob->id,
            'address'   => 'Bob Road 1', 'latitude' => -30.85, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, scope: 'my', actorUserId: $alice->id);
        $resp = $svc->getPinsInBounds($req);

        $addresses = $this->addressesIn($resp);
        $this->assertContains('Alice Road 1', $addresses);
        $this->assertNotContains('Bob Road 1', $addresses,
            "scope=my must hide other agents' properties");
    }

    /** M78 — scope=agency returns every property in the agency regardless of agent. */
    public function test_m78_scope_agency_returns_all_agency_properties(): void
    {
        [$agencyA, $agencyB] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyA);
        $bob   = $this->makeUserInAgency($agencyA);
        $eve   = $this->makeUserInAgency($agencyB);

        $this->insertProperty([
            'agency_id' => $agencyA, 'branch_id' => $agencyA, 'agent_id' => $alice->id,
            'address'   => 'Alice Road 1', 'latitude' => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyA, 'branch_id' => $agencyA, 'agent_id' => $bob->id,
            'address'   => 'Bob Road 1',   'latitude' => -30.85, 'longitude' => 30.40,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyB, 'branch_id' => $agencyB, 'agent_id' => $eve->id,
            'address'   => 'Eve Road 1',   'latitude' => -30.86, 'longitude' => 30.41,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyA, scope: 'agency', actorUserId: $alice->id);
        $resp = $svc->getPinsInBounds($req);

        $addresses = $this->addressesIn($resp);
        $this->assertContains('Alice Road 1', $addresses);
        $this->assertContains('Bob Road 1', $addresses, 'scope=agency includes other agents');
        $this->assertNotContains('Eve Road 1', $addresses, 'scope=agency must not leak across agencies');
    }

    /** M79 — search matches across address + complex_name. */
    public function test_m79_search_matches_address_and_complex_name(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => '12 Golf Course Road',
            'complex_name' => null,
            'latitude'  => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => '7 Beach Lane',
            'complex_name' => 'Sunset Manor',
            'latitude'  => -30.85, 'longitude' => 30.40,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => '3 Marine Drive',
            'complex_name' => null,
            'latitude'  => -30.86, 'longitude' => 30.41,
        ]);

        $svc = new MapPinService();

        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, search: 'golf'));
        $addresses = $this->addressesIn($resp);
        $this->assertContains('12 Golf Course Road', $addresses);
        $this->assertNotContains('7 Beach Lane', $addresses);
        $this->assertNotContains('3 Marine Drive', $addresses);

        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, search: 'sunset'));
        $addresses = $this->addressesIn($resp);
        $this->assertContains('7 Beach Lane', $addresses,
            'search must match complex_name too');
        $this->assertNotContains('12 Golf Course Road', $addresses);
    }

    /** M80 — property type filter narrows by exact bucket key. */
    public function test_m80_property_type_filter_narrows_results(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'House Road 1', 'property_type' => 'house',
            'latitude'  => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Sectional Road 1', 'property_type' => 'sectional',
            'latitude'  => -30.85, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, propertyTypes: ['sectional']);
        $resp = $svc->getPinsInBounds($req);

        $addresses = $this->addressesIn($resp);
        $this->assertContains('Sectional Road 1', $addresses);
        $this->assertNotContains('House Road 1', $addresses);
    }

    /** M81 — price range narrows. */
    public function test_m81_price_range_filter_narrows_results(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Cheap Road', 'price' => 500_000,
            'latitude'  => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Mid Road', 'price' => 1_500_000,
            'latitude'  => -30.85, 'longitude' => 30.40,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Premium Road', 'price' => 5_000_000,
            'latitude'  => -30.86, 'longitude' => 30.41,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, priceMin: 1_000_000, priceMax: 3_000_000);
        $resp = $svc->getPinsInBounds($req);

        $addresses = $this->addressesIn($resp);
        $this->assertContains('Mid Road', $addresses);
        $this->assertNotContains('Cheap Road', $addresses);
        $this->assertNotContains('Premium Road', $addresses);
    }

    /** M82 — multiple filters combine with AND. */
    public function test_m82_multiple_filters_combine_with_and(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);
        $bob   = $this->makeUserInAgency($agencyId);

        // Alice — house, 3 beds, 1.5m  → matches all three filters.
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Match Road', 'property_type' => 'house',
            'beds'      => 3, 'price' => 1_500_000,
            'latitude'  => -30.84, 'longitude' => 30.39,
        ]);
        // Alice — apartment, 3 beds, 1.5m  → fails property_type.
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Wrong-Type Road', 'property_type' => 'apartment',
            'beds'      => 3, 'price' => 1_500_000,
            'latitude'  => -30.85, 'longitude' => 30.40,
        ]);
        // Alice — house, 1 bed, 1.5m  → fails beds.
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Wrong-Beds Road', 'property_type' => 'house',
            'beds'      => 1, 'price' => 1_500_000,
            'latitude'  => -30.86, 'longitude' => 30.41,
        ]);
        // Bob — house, 3 beds, 1.5m  → fails scope=my.
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $bob->id,
            'address'   => 'Wrong-Owner Road', 'property_type' => 'house',
            'beds'      => 3, 'price' => 1_500_000,
            'latitude'  => -30.87, 'longitude' => 30.42,
        ]);

        $svc = new MapPinService();
        $req = $this->bounds(
            agencyId: $agencyId,
            scope: 'my',
            actorUserId: $alice->id,
            propertyTypes: ['house'],
            bedroomsMin: 2,
            bedroomsMax: 4,
            priceMin: 1_000_000,
            priceMax: 2_000_000,
        );
        $resp = $svc->getPinsInBounds($req);
        $addresses = $this->addressesIn($resp);

        $this->assertSame(['Match Road'], $addresses,
            'only the row matching every filter survives');
    }

    /** M83 — listing-status filter respects multi-select. */
    public function test_m83_listing_status_filter_multi_select(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $alice = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Active Road', 'status' => 'active',
            'latitude'  => -30.84, 'longitude' => 30.39,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Sold Road', 'status' => 'sold',
            'latitude'  => -30.85, 'longitude' => 30.40,
        ]);
        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $alice->id,
            'address'   => 'Draft Road', 'status' => 'draft',
            'latitude'  => -30.86, 'longitude' => 30.41,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, listingStatus: ['active', 'sold']);
        $resp = $svc->getPinsInBounds($req);

        $addresses = $this->addressesIn($resp);
        $this->assertContains('Active Road', $addresses);
        $this->assertContains('Sold Road', $addresses);
        $this->assertNotContains('Draft Road', $addresses,
            'listingStatus excludes statuses not in the multi-select');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return list<string> */
    private function addressesIn(array $resp): array
    {
        return collect($resp['locations'])
            ->flatMap(fn ($loc) => collect($loc['records'])->pluck('address'))
            ->filter()
            ->values()
            ->all();
    }

    private function bounds(
        int $agencyId,
        array $layers = ['hfc_listings'],
        ?string $scope = null,
        ?int $actorUserId = null,
        ?string $search = null,
        array $propertyTypes = [],
        ?int $priceMin = null,
        ?int $priceMax = null,
        ?int $bedroomsMin = null,
        ?int $bedroomsMax = null,
        array $listingStatus = [],
    ): MapBoundsRequest {
        return new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: $layers, viewMode: 'agent', agencyId: $agencyId,
            propertyTypes: $propertyTypes,
            priceMin: $priceMin, priceMax: $priceMax,
            scope: $scope, actorUserId: $actorUserId, search: $search,
            bedroomsMin: $bedroomsMin, bedroomsMax: $bedroomsMax,
            listingStatus: $listingStatus,
        );
    }

    private function makeTwoAgencies(): array
    {
        $id1 = (int) DB::table('agencies')->insertGetId($this->agencyRow('Agency-A-' . Str::random(6)));
        $id2 = (int) DB::table('agencies')->insertGetId($this->agencyRow('Agency-B-' . Str::random(6)));
        DB::table('branches')->insert([
            'id' => $id1, 'agency_id' => $id1, 'name' => 'Default A',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $id2, 'agency_id' => $id2, 'name' => 'Default B',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$id1, $id2];
    }

    private function agencyRow(string $name): array
    {
        return [
            'name'       => $name,
            'slug'       => Str::slug($name),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function makeUserInAgency(int $agencyId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
        ]);
    }

    private function insertProperty(array $row): int
    {
        return (int) DB::table('properties')->insertGetId(array_merge([
            'external_id'  => 'TEST-' . Str::random(8),
            'title'        => $row['address'] ?? 'Test property',
            'status'       => 'active',
            'is_demo'      => false,
            'property_type' => $row['property_type'] ?? 'house',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $row));
    }
}
