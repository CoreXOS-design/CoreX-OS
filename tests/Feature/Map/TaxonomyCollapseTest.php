<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Services\Map\LocationGrouper;
use App\Services\Map\MapBoundsRequest;
use App\Services\Map\MapPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q3 + M-collapse + Q8 — grouper behaviour for the post-taxonomy ruling.
 *
 *   Q3   tracked_properties added to PRIORITY at 100 (explicit last-rank).
 *        T-alone renders first-class; composite-with-T carries a data flag.
 *   Mc   M (mic_subjects) collapses into cma_info when a non-CMA peer
 *        exists at the same address; orphan CMA stays in records[] + sets
 *        is_cma_orphan = true.
 *   Q8   Provenance — not tested here (lives in MapPinService).
 *
 * These tests exercise the LocationGrouper directly with handcrafted
 * record dicts — no DB plumbing needed.
 */
final class TaxonomyCollapseTest extends TestCase
{
    use RefreshDatabase;

    // ── Q3 — T priority + tracked badge ─────────────────────────────────

    public function test_tracked_alone_renders_first_class_single_pin(): void
    {
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('tracked', 5, '42 Sparrow Way, Margate', 'tracked_properties'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(1, $locs[0]['record_count']);
        $this->assertFalse($locs[0]['is_composite']);
        $this->assertSame('single', $locs[0]['display_as']);
        $this->assertSame('tracked_properties', $locs[0]['primary_category'],
            'T-alone still wins primary slot when it is the only record');
        $this->assertFalse($locs[0]['has_tracked_record'],
            'has_tracked_record is for COMPOSITE-with-T; a single T pin does not need a badge');
        $this->assertFalse($locs[0]['is_cma_orphan'],
            'T is not in the CMA bucket — orphan-CMA must be false');
    }

    public function test_hfc_plus_tracked_composite_carries_tracked_badge_with_hfc_primary(): void
    {
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('hfc',     1, '8 Oak Drive, Uvongo', 'hfc_listings'),
            $this->record('tracked', 2, '8 Oak Drive, Uvongo', 'tracked_properties'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(2, $locs[0]['record_count']);
        $this->assertTrue($locs[0]['is_composite']);
        $this->assertSame('hfc_listings', $locs[0]['primary_category'],
            'H outranks T (priority 1000 > 100) — HFC takes the primary pin slot');
        $this->assertTrue($locs[0]['has_tracked_record'],
            'Composite that contains a tracked_properties record must carry the badge data flag');
        $this->assertSame('composite', $locs[0]['display_as']);
    }

    public function test_priority_ordering_is_h_over_p_over_s_over_m_over_o_over_t(): void
    {
        // All six categories at one location → primary must be HFC.
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('tracked',  1, 'X', 'tracked_properties'),
            $this->record('scheme',   2, 'X', 'scheme_owners'),
            $this->record('mic',      3, 'X', 'mic_subjects'),
            $this->record('sold',     4, 'X', 'sold_comps'),
            $this->record('active',   5, 'X', 'active_listings'),
            $this->record('hfc',      6, 'X', 'hfc_listings'),
        ]);

        $this->assertCount(1, $locs);
        // M is collapsed (non-CMA peers exist) → 5 records remain.
        $this->assertSame(5, $locs[0]['record_count'],
            'M record collapses out of records[] when non-CMA peers exist');
        $this->assertCount(1, $locs[0]['cma_info'],
            'The collapsed M is preserved as a cma_info attachment');
        $orderedCategories = array_map(fn ($r) => $r['category'], $locs[0]['records']);
        $this->assertSame(
            ['hfc_listings', 'active_listings', 'sold_comps', 'scheme_owners', 'tracked_properties'],
            $orderedCategories,
            'Records must be sorted by descending priority — T last',
        );
    }

    // ── M collapse ─────────────────────────────────────────────────────

    public function test_cma_subject_collapses_into_cma_info_when_hfc_shares_address(): void
    {
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('hfc', 1, '12 Hibiscus Avenue, Margate Beach', 'hfc_listings'),
            $this->record('mic', 7, '12 Hibiscus Avenue, Margate Beach', 'mic_subjects',
                extras: ['report_type_key' => 'cma_info_market_analysis',
                         'report_type_name' => 'CMA Info — Market Analysis',
                         'parent_report_id' => 7]),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(1, $locs[0]['record_count'],
            'Only the HFC record remains in records[]; the M record is attached as cma_info');
        $this->assertFalse($locs[0]['is_composite']);
        $this->assertSame('single', $locs[0]['display_as']);
        $this->assertSame('hfc_listings', $locs[0]['primary_category']);
        $this->assertNotContains('mic_subjects', $locs[0]['categories_present'],
            'categories_present must drop mic_subjects once it has been collapsed');
        $this->assertCount(1, $locs[0]['cma_info']);
        $this->assertSame('cma_info_market_analysis', $locs[0]['cma_info'][0]['report_type_key']);
        $this->assertFalse($locs[0]['is_cma_orphan']);
    }

    public function test_orphan_cma_subject_stays_as_pin_with_orphan_flag(): void
    {
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('mic', 9, '99 Orphan Lane', 'mic_subjects'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(1, $locs[0]['record_count']);
        $this->assertSame('single', $locs[0]['display_as']);
        $this->assertSame('mic_subjects', $locs[0]['primary_category']);
        $this->assertTrue($locs[0]['is_cma_orphan'],
            'M alone at an address is the orphan-CMA case the UI marks faint');
        $this->assertEmpty($locs[0]['cma_info'],
            'cma_info attaches collapsed peers; an orphan M is still in records[], not in cma_info');
    }

    public function test_m_plus_o_only_is_cma_orphan_but_no_collapse(): void
    {
        // Both M and O are CMA-bucket layers. Without a non-CMA peer no
        // collapse fires (records[] keeps both), and the location is still
        // CMA-orphan because every record is in the CMA bucket.
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('mic',    1, 'X', 'mic_subjects'),
            $this->record('scheme', 2, 'X', 'scheme_owners'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(2, $locs[0]['record_count'],
            'No non-CMA peer means no M collapse — both M and O stay as records');
        $this->assertEmpty($locs[0]['cma_info']);
        $this->assertTrue($locs[0]['is_cma_orphan'],
            'Every record is in {mic_subjects, scheme_owners} so the location is CMA-orphan');
    }

    public function test_scheme_owners_do_not_collapse_into_cma_info(): void
    {
        // O is CMA-subordinate per the taxonomy ruling but it does NOT
        // collapse into cma_info — only M does. O stays as a peer record
        // even when an H/S/P/T peer exists at the same address.
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('hfc',    1, '8 Oak Drive', 'hfc_listings'),
            $this->record('scheme', 2, '8 Oak Drive', 'scheme_owners'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(2, $locs[0]['record_count'],
            'O stays as a peer alongside H; only M collapses into cma_info');
        $this->assertEmpty($locs[0]['cma_info']);
        $this->assertContains('scheme_owners', $locs[0]['categories_present']);
        $this->assertSame('hfc_listings', $locs[0]['primary_category']);
    }

    public function test_tracked_alone_with_cma_subject_collapses_m_keeps_t_as_primary(): void
    {
        // T is "the spine" — when M shares its address, M collapses into
        // cma_info and T keeps its first-class pin status. This is exactly
        // the "CMA-on-tracked = no duplicate pin" proof case.
        $grouper = new LocationGrouper();
        $locs = $grouper->group([
            $this->record('tracked', 1, '5 Tracked Lane', 'tracked_properties'),
            $this->record('mic',     2, '5 Tracked Lane', 'mic_subjects'),
        ]);

        $this->assertCount(1, $locs);
        $this->assertSame(1, $locs[0]['record_count'],
            'Only T remains in records[]; M is attached as cma_info');
        $this->assertSame('tracked_properties', $locs[0]['primary_category']);
        $this->assertCount(1, $locs[0]['cma_info']);
        $this->assertFalse($locs[0]['is_cma_orphan'],
            'T is a non-CMA peer, so this location is not CMA-orphan');
        $this->assertFalse($locs[0]['has_tracked_record'],
            'has_tracked_record is for COMPOSITE-with-T; after M collapse this becomes a single T pin again');
    }

    // ── Q8 — source_class on S records ──────────────────────────────────

    public function test_q8_deals_branch_emits_source_class_own_mrcr_branch_emits_market(): void
    {
        // Seed an agency + user so MapPinService's scope filters resolve.
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Q8 ' . Str::random(6),
            'slug' => 'q8-'  . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Q8 Agent', 'email' => 'q8-' . Str::random(4) . '@test.test',
            'password' => bcrypt('x'), 'role' => 'super_admin',
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $lat = -30.870; $lng = 30.395;

        // (c) — own deal: deals ⋈ properties.
        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'Q8-OWN-' . Str::random(6),
            'title' => 'Own Sold Property',
            'address' => '5 Own Lane',
            'suburb' => 'Q8Suburb',
            'agency_id' => $agencyId,
            'branch_id'  => $agencyId,
            'agent_id'   => $userId,
            'latitude'   => $lat - 0.001,
            'longitude'  => $lng - 0.001,
            'price'      => 1800000,
            'property_type' => 'house',
            'status'     => 'sold',
            'is_demo'    => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals')->insert([
            'property_id' => $propertyId,
            'agency_id'   => $agencyId,
            'branch_id'   => $agencyId,
            'registration_date' => now()->subDays(30)->toDateString(),
            'sale_price'  => 1800000,
            'property_value'   => 1800000,        // NOT NULL no default
            'total_commission' => 90000,          // NOT NULL no default
            'property_address' => '5 Own Lane',
            'file_no'     => 'Q8FN-' . Str::random(6),
            'period'      => now()->format('Y-m'),
            'deal_date'   => now()->subDays(30)->toDateString(),
            'is_demo'     => false,
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        // (a) — market MRCR: market_report_comp_rows row_type='comp' joined
        // to a parent market_reports row. Needs lat/lng OR a sibling scheme
        // subject GPS — we put GPS directly on the MRCR row for simplicity.
        $reportTypeId = (int) DB::table('market_report_types')->insertGetId([
            'key' => 'q8_test', 'display_name' => 'Q8 Test',
            'parser_class' => 'App\\Services\\MarketReports\\Parsers\\GenericFallbackParser',
            'expected_fields_json' => json_encode([]),
            'auto_approve' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reportId = (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => $reportTypeId,
            'file_path'           => 'fake/q8.pdf',
            'file_name'           => 'Q8.pdf',
            'file_hash'           => hash('sha256', Str::random(64)),
            'report_date'         => now()->toDateString(),
            'parse_status'        => 'parsed',
            'spot_check_status'   => 'passed',
            'data_points_count'   => 1,
            'is_demo'             => false,
            'created_at'          => now(), 'updated_at' => now(),
        ]);
        DB::table('market_report_comp_rows')->insert([
            'market_report_id' => $reportId,
            'agency_id'        => $agencyId,
            'row_index'        => 0,
            'row_type'         => 'comp',
            'address'          => '7 Market Lane',
            'sale_date'        => now()->subDays(60)->toDateString(),
            'sale_price'       => 1500000,
            'latitude'         => $lat + 0.001,
            'longitude'        => $lng + 0.001,
            'created_at'       => now(), 'updated_at' => now(),
        ]);

        // Run soldComps via the service.
        $service = new MapPinService();
        $req = new MapBoundsRequest(
            north: $lat + 0.01, south: $lat - 0.01,
            east:  $lng + 0.01, west:  $lng - 0.01,
            layers: ['sold_comps'], viewMode: 'agent', agencyId: $agencyId,
            actorUserId: $userId,
        );
        $payload = $service->getPinsInBounds($req);
        $rawPins = [];
        foreach ($payload['locations'] as $loc) {
            foreach ($loc['records'] as $r) {
                if (($r['category'] ?? null) === 'sold_comps') $rawPins[] = $r;
            }
        }

        $own = array_values(array_filter($rawPins, fn ($p) => str_starts_with($p['id'] ?? '', 'deal:')));
        $market = array_values(array_filter($rawPins, fn ($p) => str_starts_with($p['id'] ?? '', 'mrcr:')));

        $this->assertNotEmpty($own,    'deals branch must produce at least one own-sold pin');
        $this->assertNotEmpty($market, 'MRCR branch must produce at least one market-sold pin');

        $this->assertSame('own', $own[0]['source_class'] ?? null,
            "deals ⋈ properties is HFC's own history — source_class MUST be 'own'");
        $this->assertSame('market', $market[0]['source_class'] ?? null,
            "MRCR comp rows are scraped market data — source_class MUST be 'market'");

        // Q8 says NOT NULL with default 'market'. The records emitted from
        // soldComps must always carry the field — no null, no missing key.
        foreach ($rawPins as $p) {
            $this->assertArrayHasKey('source_class', $p,
                'every S-layer record must carry source_class as a NOT-NULL data field');
            $this->assertContains($p['source_class'], ['own', 'market'],
                'source_class must be exactly one of {own, market}');
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $extras */
    private function record(
        string $source,
        int $id,
        string $title,
        string $category,
        ?string $address = null,
        float $lat = -30.84,
        float $lng = 30.39,
        array $extras = [],
    ): array {
        return array_merge([
            'id'         => $id,
            'category'   => $category,
            'title'      => $title,
            'subtitle'   => $source,
            'address'    => $address ?? $title,
            'lat'        => $lat,
            'lng'        => $lng,
            'detail_url' => '/test/' . $source . '/' . $id,
        ], $extras);
    }
}
