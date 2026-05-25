<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\User;
use App\Services\Map\LocationGrouper;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.1 Part 4 — backend tests M1-M5 for the composite-pin pipeline.
 *
 * Strategy:
 *   - M1, M2, M5 hit the LocationGrouper directly with handcrafted records.
 *     They don't need the DB plumbing and prove the grouping algorithm in
 *     isolation (faster, more focused failures).
 *   - M3, M4 exercise MapPinService end-to-end against seeded MySQL rows —
 *     these prove the SQL layer + grouper compose correctly and that
 *     agency scope / layer filters take effect BEFORE grouping.
 */
final class MapLocationsTest extends TestCase
{
    use RefreshDatabase;

    /** M1 — two records sharing a geocode_target collapse to one composite location. */
    public function test_m1_records_with_same_geocode_target_group_into_one_location(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            $this->record('properties', 1, '36 Ss Topanga, 2587 Colin Road, Uvongo', 'hfc_listings'),
            $this->record('properties', 2, '37 Ss Topanga, 2587 Colin Road, Uvongo', 'hfc_listings'),
            $this->record('schemes',    9, 'Topanga § 12',                            'scheme_owners', address: '2587 Colin Road, Uvongo'),
        ];

        $locs = $grouper->group($records);

        $this->assertCount(1, $locs, 'all three records share the building street address — one composite');
        $this->assertSame(3, $locs[0]['record_count']);
        $this->assertTrue($locs[0]['is_composite']);
        $this->assertSame('hfc_listings', $locs[0]['primary_category'], 'HFC outranks scheme_owners');
        $this->assertContains('hfc_listings', $locs[0]['categories_present']);
        $this->assertContains('scheme_owners', $locs[0]['categories_present']);
        // A.1.1 — GPS-primary grouping. All three records share lat/lng,
        // so they collapse via the gps: key (no longer via geocode_target).
        $this->assertSame('gps', $locs[0]['grouping_basis']);
    }

    /** M2 — distinct addresses stay un-grouped; each is a single-record location. */
    public function test_m2_distinct_addresses_render_as_single_record_locations(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            $this->record('a', 1, '18 Golf Course Road, Uvongo', 'hfc_listings', lat: -30.84, lng: 30.39),
            $this->record('b', 2, '12 Bairn Street, Uvongo',     'hfc_listings', lat: -30.85, lng: 30.40),
        ];

        $locs = $grouper->group($records);

        $this->assertCount(2, $locs);
        foreach ($locs as $loc) {
            $this->assertFalse($loc['is_composite']);
            $this->assertSame(1, $loc['record_count']);
        }
    }

    /** M3 — agency scope: a property in another agency must not appear for this user. */
    public function test_m3_pin_endpoint_respects_agency_scope(): void
    {
        [$agencyA, $agencyB] = $this->makeTwoAgencies();
        $userA = $this->makeUserInAgency($agencyA);

        $this->insertProperty([
            'agency_id' => $agencyA, 'branch_id' => $agencyA, 'agent_id' => $userA->id,
            'address'   => '18 Golf Course Road', 'suburb' => 'Uvongo',
            'latitude'  => -30.84, 'longitude' => 30.39, 'price' => 1_200_000,
            'property_type' => 'house',
        ]);
        $this->insertProperty([
            'agency_id' => $agencyB, 'branch_id' => $agencyB, 'agent_id' => $userA->id,
            'address'   => '12 Bairn Street', 'suburb' => 'Uvongo',
            'latitude'  => -30.85, 'longitude' => 30.40, 'price' => 1_500_000,
            'property_type' => 'house',
        ]);

        $svc = new MapPinService();
        $req = $this->bounds(agencyId: $agencyA);
        $resp = $svc->getPinsInBounds($req);

        $addresses = collect($resp['locations'])
            ->flatMap(fn ($loc) => collect($loc['records'])->pluck('address'))
            ->all();

        $this->assertContains('18 Golf Course Road', $addresses);
        $this->assertNotContains('12 Bairn Street', $addresses, 'agency B property must be invisible to agency A');
    }

    /** M4 — layer filter narrows records BEFORE grouping. */
    public function test_m4_layer_filter_excludes_disabled_categories_from_records(): void
    {
        [$agencyA] = $this->makeTwoAgencies();
        $userA = $this->makeUserInAgency($agencyA);

        $this->insertProperty([
            'agency_id' => $agencyA, 'branch_id' => $agencyA, 'agent_id' => $userA->id,
            'address'   => '18 Golf Course Road', 'suburb' => 'Uvongo',
            'latitude'  => -30.84, 'longitude' => 30.39, 'price' => 1_200_000,
            'property_type' => 'house',
        ]);

        $svc = new MapPinService();
        // Request only sold_comps — HFC properties must not appear.
        $req = $this->bounds(agencyId: $agencyA, layers: ['sold_comps']);
        $resp = $svc->getPinsInBounds($req);

        $categories = collect($resp['locations'])
            ->flatMap(fn ($loc) => collect($loc['records'])->pluck('category'))
            ->unique()
            ->values()
            ->all();

        $this->assertNotContains('hfc_listings', $categories, 'HFC must be excluded when layer not requested');
    }

    /** M5 — composite location.records[] contains every grouped record with full payload. */
    public function test_m5_composite_location_records_preserve_full_record_payload(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            $this->record('a', 11, '36 Ss Topanga, 2587 Colin Road, Uvongo', 'hfc_listings'),
            $this->record('b', 12, 'Topanga § 12', 'scheme_owners', address: '2587 Colin Road, Uvongo'),
            $this->record('c', 13, 'Topanga § 14', 'scheme_owners', address: '2587 Colin Road, Uvongo'),
        ];

        $locs = $grouper->group($records);

        $this->assertCount(1, $locs);
        $loc = $locs[0];
        $this->assertSame(3, $loc['record_count']);
        $this->assertCount(3, $loc['records']);

        // Each record carries its id, category, title, detail_url (the V1
        // wire field; MapPinService::toRecord() exposes it as `deep_link`
        // on the public response — that rename is tested at the endpoint
        // boundary in M3/M4 above, not here on the grouper directly).
        foreach ($loc['records'] as $rec) {
            $this->assertArrayHasKey('id', $rec);
            $this->assertArrayHasKey('category', $rec);
            $this->assertArrayHasKey('title', $rec);
            $this->assertArrayHasKey('detail_url', $rec);
        }

        $ids = array_column($loc['records'], 'id');
        $this->assertEqualsCanonicalizing([11, 12, 13], $ids, 'all three record ids survived grouping');

        // Primary record is the HFC listing (priority 1000 > scheme_owners 200).
        $this->assertSame('hfc_listings', $loc['records'][0]['category']);
    }

    // ── Part 1 bug-fix coverage (A.1.1 + A.1.2) ──────────────────────────

    /** M12 — A.1.1 fix. Two records at *identical* GPS but parsing to
     *        different geocode_target strings now collapse to ONE composite
     *        location (the Highland Park / Sunset Manor § N case found in
     *        live data). Pre-fix this was the duplicate-pin bug. */
    public function test_m12_same_gps_different_geocode_targets_collapse(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            // Same lat/lng, parser produces a different geocode_target for
            // each because their raw addresses differ. With GPS-primary they
            // group cleanly.
            $this->record('a', 1, 'Highland Park, 12', 'hfc_listings',
                address: 'Highland Park, 12', lat: -30.7945098, lng: 30.4144902),
            $this->record('b', 2, 'Highland Park',     'mic_subjects',
                address: 'Highland Park',     lat: -30.7945098, lng: 30.4144902),
        ];

        $locs = $grouper->group($records);

        $this->assertCount(1, $locs, 'same-GPS records must group regardless of parsed address divergence');
        $this->assertSame(2, $locs[0]['record_count']);
        $this->assertSame('gps', $locs[0]['grouping_basis']);
    }

    /** M13 — A.1.1 fallback. Records with lat/lng = 0.0 (Phase 3f's "not
     *        yet resolved" sentinel — actual null is filtered upstream)
     *        STILL group by their parsed geocode_target. This is the safety
     *        net for legacy / partially-geocoded data. */
    public function test_m13_zero_gps_groups_via_geocode_target_fallback(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            // lat/lng = 0.0 — keyFor() rejects these as GPS-mappable and
            // falls through to the geocode_target branch.
            ['id' => 1, 'category' => 'hfc_listings',  'title' => '2587 Colin Road, Uvongo',
             'address' => '2587 Colin Road, Uvongo', 'lat' => 0.0, 'lng' => 0.0, 'detail_url' => '/x/1'],
            ['id' => 2, 'category' => 'scheme_owners', 'title' => 'Topanga § 12',
             'address' => '2587 Colin Road, Uvongo', 'lat' => 0.0, 'lng' => 0.0, 'detail_url' => '/x/2'],
        ];

        $locs = $grouper->group($records);

        $this->assertCount(1, $locs, 'fallback grouping by geocode_target when GPS unusable');
        $this->assertSame(2, $locs[0]['record_count']);
        $this->assertSame('geocode_target', $locs[0]['grouping_basis']);
    }

    /** M14 — distant records (>100m apart) stay as SEPARATE locations even
     *        when their parsed geocode_target happens to match. 5dp rounding
     *        ≈1m, so any pin pair more than ~2m apart hashes differently. */
    public function test_m14_records_200m_apart_remain_separate(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            // ~200m apart along the latitude axis (~0.002° ≈ 220m at the
            // equator; close enough on the KZN coast).
            $this->record('a', 1, '2587 Colin Road', 'hfc_listings', lat: -30.84000, lng: 30.39000),
            $this->record('b', 2, '2587 Colin Road', 'hfc_listings', lat: -30.84200, lng: 30.39000),
        ];

        $locs = $grouper->group($records);

        $this->assertCount(2, $locs, 'records 200m apart must NOT merge — 5dp rounding keeps them separate');
        $this->assertSame(1, $locs[0]['record_count']);
        $this->assertSame(1, $locs[1]['record_count']);
    }

    /** M15 — server-side layer filter. Requesting only sold_comps excludes
     *        hfc_listings rows from the response entirely (the underlying
     *        per-layer fetcher is never invoked, so the records list can't
     *        contain HFC entries). */
    public function test_m15_pin_endpoint_excludes_records_of_inactive_categories(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $userA = $this->makeUserInAgency($agencyId);

        // Insert one HFC property — should not appear when layers=[sold_comps].
        $this->insertProperty([
            'agency_id'     => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userA->id,
            'address'       => '18 Golf Course Road', 'suburb' => 'Uvongo',
            'latitude'      => -30.84, 'longitude' => 30.39,
            'price'         => 1_200_000, 'property_type' => 'house',
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = $this->bounds(agencyId: $agencyId, layers: ['sold_comps']);
        $resp = $svc->getPinsInBounds($req);

        $cats = collect($resp['locations'])
            ->flatMap(fn ($l) => collect($l['records'])->pluck('category'))
            ->unique()->values()->all();

        $this->assertNotContains('hfc_listings', $cats, 'HFC excluded when layer not requested');
        $this->assertArrayNotHasKey('hfc_listings', $resp['layer_counts'],
            'unrequested layers are not fetched, so absent from layer_counts');
    }

    /** M16 — the locations returned for a layer-filtered request have
     *        records[] containing ONLY records of requested categories. This
     *        is the purity guarantee the client-side renderer relies on. */
    public function test_m16_locations_records_pure_for_layer_filter(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $userA = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userA->id,
            'address'   => '18 Golf Course Road', 'suburb' => 'Uvongo',
            'latitude'  => -30.84, 'longitude' => 30.39, 'price' => 1_200_000, 'property_type' => 'house',
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = $this->bounds(agencyId: $agencyId, layers: ['hfc_listings']);
        $resp = $svc->getPinsInBounds($req);

        $this->assertNotEmpty($resp['locations']);
        foreach ($resp['locations'] as $loc) {
            foreach ($loc['records'] as $rec) {
                $this->assertSame('hfc_listings', $rec['category'],
                    'record leaked from a disabled layer');
            }
        }
    }

    // ── A.2.3 Item 2 — display_as field ──────────────────────────────────

    /** M32 — scheme-only composite (all records are scheme_owners) → display_as=scheme. */
    public function test_m32_scheme_only_composite_is_display_as_scheme(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            $this->record('a', 1, 'Sunset Manor § 1', 'scheme_owners', address: 'Sunset Manor § 1'),
            $this->record('b', 2, 'Sunset Manor § 2', 'scheme_owners', address: 'Sunset Manor § 2'),
            $this->record('c', 3, 'Sunset Manor § 3', 'scheme_owners', address: 'Sunset Manor § 3'),
        ];

        $locs = $grouper->group($records);
        $this->assertCount(1, $locs);
        $this->assertSame('scheme', $locs[0]['display_as']);
        $this->assertSame('Sunset Manor', $locs[0]['scheme_name']);
        $this->assertSame(3, $locs[0]['record_count']);
    }

    /** M33 — mixed-category composite → display_as=composite. */
    public function test_m33_mixed_category_composite_is_display_as_composite(): void
    {
        $grouper = new LocationGrouper();

        $records = [
            $this->record('a', 1, 'Sunset Manor § 1', 'scheme_owners', address: 'Sunset Manor § 1'),
            $this->record('b', 2, 'Sunset Manor § 2', 'scheme_owners', address: 'Sunset Manor § 2'),
            $this->record('c', 3, '12 Beach Road',     'hfc_listings',  address: 'Sunset Manor § 1'),
        ];

        $locs = $grouper->group($records);
        $this->assertCount(1, $locs);
        $this->assertSame('composite', $locs[0]['display_as']);
        $this->assertArrayNotHasKey('scheme_name', $locs[0],
            'scheme_name only present when display_as=scheme');
    }

    /** M34 — single record → display_as=single. */
    public function test_m34_single_record_is_display_as_single(): void
    {
        $grouper = new LocationGrouper();
        $records = [
            $this->record('a', 1, '18 Golf Course Road', 'hfc_listings', lat: -30.84, lng: 30.39),
        ];
        $locs = $grouper->group($records);
        $this->assertCount(1, $locs);
        $this->assertSame('single', $locs[0]['display_as']);
    }

    /** M35 — full endpoint response exposes display_as on every location
     *        so the client renderer can switch pin visuals without a
     *        re-derivation pass. */
    public function test_m35_endpoint_response_carries_display_as_per_location(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $userA = $this->makeUserInAgency($agencyId);

        $this->insertProperty([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userA->id,
            'address'   => '18 Golf Course Road', 'suburb' => 'Uvongo',
            'latitude'  => -30.84, 'longitude' => 30.39, 'price' => 1_200_000,
            'property_type' => 'house',
        ]);

        $svc = new \App\Services\Map\MapPinService();
        $req = $this->bounds(agencyId: $agencyId, layers: ['hfc_listings']);
        $resp = $svc->getPinsInBounds($req);

        $this->assertNotEmpty($resp['locations']);
        foreach ($resp['locations'] as $loc) {
            $this->assertContains($loc['display_as'], ['scheme', 'composite', 'single'],
                'display_as must be one of three known values');
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function record(
        string $source, int $id, string $title, string $category,
        ?string $address = null, float $lat = -30.84, float $lng = 30.39,
    ): array {
        return [
            'id'        => $id,
            'category'  => $category,
            'title'     => $title,
            'subtitle'  => $source,
            'address'   => $address ?? $title,
            'lat'       => $lat,
            'lng'       => $lng,
            'detail_url' => '/test/' . $source . '/' . $id,
        ];
    }

    private function bounds(int $agencyId, array $layers = ['hfc_listings','sold_comps','active_listings','mic_subjects','scheme_owners']): MapBoundsRequest
    {
        return new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: $layers, viewMode: 'agent', agencyId: $agencyId,
        );
    }

    private function makeTwoAgencies(): array
    {
        $id1 = (int) DB::table('agencies')->insertGetId($this->agencyRow('Agency-A-' . Str::random(6)));
        $id2 = (int) DB::table('agencies')->insertGetId($this->agencyRow('Agency-B-' . Str::random(6)));

        // Branches are also commonly required — reuse the agency id as branch id
        // by inserting a branch under each. Some test rows reference branch_id
        // for the property insert.
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
            'external_id' => 'TEST-' . Str::random(8),
            'title'       => $row['address'] ?? 'Test property',
            'status'      => 'active',
            'is_demo'     => false,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $row));
    }
}
