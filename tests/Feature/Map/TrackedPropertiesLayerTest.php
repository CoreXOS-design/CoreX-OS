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
 * Tracked Properties (T) layer — wires the 2026-05-27 Google geocoding
 * backfill into the map. Covers:
 *   - Agency scope respected (other agency's TPs invisible)
 *   - promoted_to_property_id IS NOT NULL → row excluded (the H layer
 *     already covers promoted rows)
 *   - latitude IS NULL → row excluded
 *   - geocode_needs_review = 1 → row excluded (the 165 flagged outliers
 *     from the 2026-05-27 cleanup)
 *   - status != 'active' → row excluded
 *   - Seller View → layer entirely absent from response (sensitive)
 *   - Agent View + bounds with geocoded rows → pins returned
 *   - Search filter (street_name / suburb / erf_number) narrows results
 */
final class TrackedPropertiesLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_view_returns_geocoded_tracked_properties_in_bounds(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        $inBoundsId = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => '12 Marine Drive',
            'suburb'     => 'Margate',
            'latitude'   => -30.8627, 'longitude' => 30.3719,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, layers: ['tracked_properties']);
        $resp = $svc->getPinsInBounds($req);

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($inBoundsId, $ids,
            'in-bounds active geocoded TP must appear in agent view');
        $this->assertSame(1, $resp['layer_counts']['tracked_properties'] ?? 0);
    }

    public function test_seller_view_suppresses_tracked_properties_layer(): void
    {
        [$agencyId] = $this->makeTwoAgencies();
        $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => '12 Marine Drive',
            'suburb'     => 'Margate',
            'latitude'   => -30.8627, 'longitude' => 30.3719,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyId, layers: ['tracked_properties'], viewMode: 'seller');
        $resp = $svc->getPinsInBounds($req);

        $this->assertSame(0, $resp['layer_counts']['tracked_properties'] ?? -1,
            'tracked_properties is sensitive — Seller View must return 0 pins');
        $this->assertEmpty($this->trackedIdsIn($resp),
            'no TP records should leak into Seller View locations');
    }

    public function test_agency_scope_respected(): void
    {
        [$agencyA, $agencyB] = $this->makeTwoAgencies();

        $mineId  = $this->insertTp([
            'agency_id'  => $agencyA,
            'street_name' => 'Mine 1',
            'latitude'   => -30.85, 'longitude' => 30.39,
        ]);
        $theirId = $this->insertTp([
            'agency_id'  => $agencyB,
            'street_name' => 'Theirs 1',
            'latitude'   => -30.86, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $req  = $this->bounds(agencyId: $agencyA, layers: ['tracked_properties']);
        $resp = $svc->getPinsInBounds($req);

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($mineId, $ids);
        $this->assertNotContains($theirId, $ids, "cross-agency TP must not be visible");
    }

    public function test_promoted_rows_excluded(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        // promoted_to_property_id has a FK to properties.id, so we need a
        // real property row to satisfy the constraint. Also seed an agent
        // because properties.agent_id is NOT NULL.
        $agentId = (int) User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
        ])->id;
        $realPropertyId = (int) DB::table('properties')->insertGetId([
            'external_id'  => 'PROP-' . Str::random(8),
            'agency_id'    => $agencyId,
            'branch_id'    => $agencyId,
            'agent_id'     => $agentId,
            'address'      => 'Promoted Lane (live)',
            'title'        => 'Promoted Lane (live)',
            'status'       => 'active',
            'property_type' => 'house',
            'latitude'     => -30.85, 'longitude' => 30.39,
            'created_at'   => now(), 'updated_at' => now(),
        ]);

        $promotedId = $this->insertTp([
            'agency_id'              => $agencyId,
            'street_name'             => 'Promoted Lane',
            'latitude'                => -30.85, 'longitude' => 30.39,
            'promoted_to_property_id' => $realPropertyId,
        ]);
        $unpromotedId = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Not Promoted Lane',
            'latitude'   => -30.86, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, layers: ['tracked_properties']));

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($unpromotedId, $ids);
        $this->assertNotContains($promotedId, $ids,
            'promoted TPs already render via the H layer — must not double-count');
    }

    public function test_null_gps_rows_excluded(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        $noGpsId = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'No GPS Yet',
            'latitude'   => null, 'longitude' => null,
        ]);
        $gpsId   = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Has GPS',
            'latitude'   => -30.85, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, layers: ['tracked_properties']));

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($gpsId, $ids);
        $this->assertNotContains($noGpsId, $ids, 'null-GPS rows must not pin');
    }

    public function test_needs_review_rows_excluded(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        $needsReviewId = $this->insertTp([
            'agency_id'             => $agencyId,
            'street_name'            => 'Outlier — flagged',
            'latitude'               => -30.85, 'longitude' => 30.39,
            'geocode_needs_review'   => 1,
        ]);
        $cleanId       = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Clean GPS',
            'latitude'   => -30.86, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, layers: ['tracked_properties']));

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($cleanId, $ids);
        $this->assertNotContains($needsReviewId, $ids,
            'geocode_needs_review rows must be hidden until an operator clears them');
    }

    public function test_non_active_status_rows_excluded(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        $archivedId = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Archived',
            'latitude'   => -30.85, 'longitude' => 30.39,
            'status'     => 'archived',
        ]);
        $activeId   = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Active',
            'latitude'   => -30.86, 'longitude' => 30.40,
            'status'     => 'active',
        ]);

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds($this->bounds(agencyId: $agencyId, layers: ['tracked_properties']));

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($activeId, $ids);
        $this->assertNotContains($archivedId, $ids);
    }

    public function test_raised_cap_lets_dense_suburb_zoom_return_more_than_333_pins(): void
    {
        // Phase 9a's zoom-aware cap is effectiveLimit/layerCount = 2000/6 = 333
        // at suburb zoom. tracked_properties bumps via perLayerLimitFor() to
        // min(max(333*3, 1000), 1500) = 1000. Verify that with 1200 seeded TPs
        // the response returns close to 1000 — not capped at 333.
        [$agencyId] = $this->makeTwoAgencies();

        $seedCount = 1200;
        $rows = [];
        for ($i = 0; $i < $seedCount; $i++) {
            // Spread within the bounding box (-30.4, -31.0, 30.0, 30.9).
            $lat = -30.5 - ($i % 400) * 0.001;
            $lng = 30.10 + ($i % 700) * 0.001;
            $rows[] = [
                'external_id'   => 'TP-DENSE-' . $i,
                'agency_id'     => $agencyId,
                'status'        => 'active',
                'street_name'   => 'Dense Street ' . $i,
                'latitude'      => $lat,
                'longitude'     => $lng,
                'first_seen_at' => now()->subDays(30),
                'created_at'    => now()->subDays(30),
                'updated_at'    => now(),
            ];
        }
        // Insert in chunks to avoid placeholder limits.
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('tracked_properties')->insert($chunk);
        }

        // 6 layers, suburb-zoom bounding box (the bounds() helper's box is
        // span 0.6° × 0.9° — already region-ish, so widen to a smaller box
        // for the true suburb-zoom path).
        $req = new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners', 'tracked_properties'],
            viewMode: 'agent',
            agencyId: $agencyId,
            scope: 'agency',
            actorUserId: null,
        );

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds($req);

        $countReturned = $resp['layer_counts']['tracked_properties'] ?? 0;
        $this->assertGreaterThan(333, $countReturned,
            'cap must lift above zoom-aware-base 333 for tracked_properties');
        $this->assertLessThanOrEqual(1500, $countReturned,
            'cap must not exceed 1500 ceiling');
    }

    public function test_un_bumped_layers_unchanged_by_per_layer_override(): void
    {
        // Sanity — perLayerLimitFor() leaves the un-bumped layers
        // (hfc_listings, sold_comps, mic_subjects, scheme_owners) at the
        // zoom-aware base. tracked_properties AND active_listings (post-
        // MAP-CAP) both bump to the 1000-1500 envelope.
        $req = new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: ['hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners', 'tracked_properties'],
            viewMode: 'agent',
            agencyId: 1,
            scope: 'agency',
            actorUserId: null,
        );

        $baseZoom = $req->zoomAwarePerLayerLimit(6);
        $this->assertSame($baseZoom, $req->perLayerLimitFor('hfc_listings', 6));
        $this->assertSame($baseZoom, $req->perLayerLimitFor('sold_comps', 6));
        $this->assertSame($baseZoom, $req->perLayerLimitFor('mic_subjects', 6));
        $this->assertSame($baseZoom, $req->perLayerLimitFor('scheme_owners', 6));
        // Both bumped layers in the 1000-1500 envelope.
        $this->assertGreaterThanOrEqual(1000, $req->perLayerLimitFor('tracked_properties', 6));
        $this->assertLessThanOrEqual(1500, $req->perLayerLimitFor('tracked_properties', 6));
        $this->assertGreaterThanOrEqual(1000, $req->perLayerLimitFor('active_listings', 6));
        $this->assertLessThanOrEqual(1500, $req->perLayerLimitFor('active_listings', 6));
    }

    public function test_search_filter_narrows_by_street_name(): void
    {
        [$agencyId] = $this->makeTwoAgencies();

        $marineId = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Marine Drive',
            'suburb'     => 'Margate',
            'latitude'   => -30.85, 'longitude' => 30.39,
        ]);
        $oakId    = $this->insertTp([
            'agency_id'  => $agencyId,
            'street_name' => 'Oak Street',
            'suburb'     => 'Margate',
            'latitude'   => -30.86, 'longitude' => 30.40,
        ]);

        $svc  = new MapPinService();
        $resp = $svc->getPinsInBounds(
            $this->bounds(agencyId: $agencyId, layers: ['tracked_properties'], search: 'marine'),
        );

        $ids = $this->trackedIdsIn($resp);
        $this->assertContains($marineId, $ids);
        $this->assertNotContains($oakId, $ids, 'search="marine" must filter out non-matching streets');
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /** @return list<int> */
    private function trackedIdsIn(array $resp): array
    {
        return collect($resp['locations'])
            ->flatMap(fn ($loc) => collect($loc['records'])->where('category', 'tracked_properties')->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function bounds(
        int $agencyId,
        array $layers = ['tracked_properties'],
        string $viewMode = 'agent',
        ?string $search = null,
    ): MapBoundsRequest {
        return new MapBoundsRequest(
            north: -30.4, south: -31.0, east: 30.9, west: 30.0,
            layers: $layers,
            viewMode: $viewMode,
            agencyId: $agencyId,
            scope: 'agency',
            actorUserId: null,
            search: $search,
        );
    }

    /** @return array{int, int} */
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

    private function insertTp(array $row): int
    {
        return (int) DB::table('tracked_properties')->insertGetId(array_merge([
            'external_id'   => 'TP-' . Str::random(8),
            'status'        => 'active',
            'first_seen_at' => now()->subDays(30),
            'created_at'    => now()->subDays(30),
            'updated_at'    => now(),
        ], $row));
    }
}
