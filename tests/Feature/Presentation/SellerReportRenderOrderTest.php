<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B2-followup-3 — Render-order smoke tests + jargon regression guard.
 *
 * Spec: .ai/specs/seller-report-restructure.md
 *
 * These tests run the full buildHtml() output against a seeded full-data
 * presentation and assert:
 *
 *   1. Physical block ordering matches the spec §1 beat order:
 *        Cover  →  Executive Summary  →  Beat 1 (Your Property)
 *               →  Beat 2 (What's Happened Around You)
 *               →  Beat 3 (What's On The Market Now)
 *               →  Beat 4 (Where You Should Be)
 *               →  Beat 5 (What Waiting Costs)
 *               →  Appendix
 *
 *   2. Beat 2 internal ordering after B2-followup-3:
 *        Recent Sales  →  Market Overview  →  Spatial.
 *
 *   3. Every beat banner's "Section N · Beat M" prefix reads the same N
 *      that buildSummaryPayload() emitted for that beat in $summary
 *      ['section_index']. No hardcoded drift between the bullet's → p.N
 *      ref and the printed banner.
 *
 *   4. Jargon regression guard — none of the spec §1 forbidden seller-
 *      facing phrases survive in the rendered HTML.
 */
final class SellerReportRenderOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    public function test_full_data_html_renders_beats_in_spec_order(): void
    {
        [$presentation, $version] = $this->seedFullDataSubject();

        $html = (new PresentationPdfService())->buildHtml($version);

        // Cover / Exec Summary precedes every beat banner.
        $execIdx   = strpos($html, 'Executive Summary');
        $beat1Idx  = strpos($html, 'Beat 1 — Your Property');
        $beat2Idx  = strpos($html, "Beat 2 — What's Happened Around You");
        $beat3Idx  = strpos($html, "Beat 3 — What's On The Market Now");
        $beat4Idx  = strpos($html, 'Beat 4 — Where You Should Be');
        $beat5Idx  = strpos($html, 'Beat 5 — What Waiting Costs');

        $this->assertNotFalse($execIdx,  'Executive Summary header must render');
        $this->assertNotFalse($beat1Idx, 'Beat 1 banner must render');
        $this->assertNotFalse($beat2Idx, 'Beat 2 banner must render');
        $this->assertNotFalse($beat3Idx, 'Beat 3 banner must render');
        $this->assertNotFalse($beat4Idx, 'Beat 4 banner must render');
        $this->assertNotFalse($beat5Idx, 'Beat 5 banner must render');

        // Cover/Exec → Beat 1 → Beat 2 → Beat 3 → Beat 4 → Beat 5.
        $this->assertLessThan($beat1Idx, $execIdx,  'Executive Summary must appear before Beat 1');
        $this->assertLessThan($beat2Idx, $beat1Idx, 'Beat 1 must appear before Beat 2');
        $this->assertLessThan($beat3Idx, $beat2Idx, 'Beat 2 must appear before Beat 3');
        $this->assertLessThan($beat4Idx, $beat3Idx, 'Beat 3 must appear before Beat 4');
        $this->assertLessThan($beat5Idx, $beat4Idx, 'Beat 4 must appear before Beat 5');

        // Subject card lives inside Beat 1. Holding Cost lives inside Beat 5.
        // Match the body-rendered <div class="subject-card"> — not "SUBJECT
        // PROPERTY" which also appears in CSS comments at the top of the
        // doc and would match before any beat banner.
        $subjectIdx     = strpos($html, 'class="subject-card"');
        $cmaIdx         = strpos($html, 'Comparative Market Analysis');
        $holdingCostIdx = strpos($html, 'Holding Cost Analysis');

        $this->assertNotFalse($subjectIdx, 'Subject card must render in Beat 1');
        $this->assertNotFalse($cmaIdx, 'CMA section must render in Beat 4');
        $this->assertNotFalse($holdingCostIdx, 'Holding Cost section must render in Beat 5');

        // Subject card must follow the Beat 1 banner — B2-followup-1 Move A.
        $this->assertGreaterThan($beat1Idx, $subjectIdx, 'Subject card must follow Beat 1 banner');

        // CMA (Beat 4) must come after Active Competition (Beat 3) per spec §1.
        $activeCompIdx = strpos($html, 'Active Competition');
        $this->assertNotFalse($activeCompIdx, 'Active Competition section must render');
        $this->assertLessThan($cmaIdx, $activeCompIdx, 'Active Competition (Beat 3) must precede CMA (Beat 4)');

        // Holding Cost (Beat 5) must come after CMA (Beat 4).
        $this->assertGreaterThan($cmaIdx, $holdingCostIdx, 'Holding Cost (Beat 5) must follow CMA (Beat 4)');
    }

    public function test_beat_2_internal_order_recent_sales_then_market_overview(): void
    {
        [$presentation, $version] = $this->seedFullDataSubject();

        $html = (new PresentationPdfService())->buildHtml($version);

        $beat2Idx        = strpos($html, "Beat 2 — What's Happened Around You");
        $recentSalesIdx  = strpos($html, 'Recent Sales Near Your Property');
        $marketOvIdx     = strpos($html, 'Market Overview —');
        $beat3Idx        = strpos($html, "Beat 3 — What's On The Market Now");

        $this->assertNotFalse($beat2Idx);
        $this->assertNotFalse($recentSalesIdx, 'Recent Sales H2 must render in Beat 2');
        $this->assertNotFalse($marketOvIdx, 'Market Overview H2 must render in Beat 2');
        $this->assertNotFalse($beat3Idx);

        // Recent Sales must follow the Beat 2 banner and precede Market Overview.
        $this->assertGreaterThan($beat2Idx, $recentSalesIdx, 'Recent Sales must follow Beat 2 banner');
        $this->assertLessThan($marketOvIdx, $recentSalesIdx, 'Recent Sales must precede Market Overview');

        // Market Overview still belongs to Beat 2 — must fall before Beat 3 banner.
        $this->assertLessThan($beat3Idx, $marketOvIdx, 'Market Overview must precede Beat 3 banner');
    }

    public function test_beat_banner_section_numbers_match_summary_payload(): void
    {
        [$presentation, $version] = $this->seedFullDataSubject();

        $svc     = new PresentationPdfService();
        $data    = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = $svc->buildSummaryPayload($presentation, $version, $data);
        $html    = $svc->buildHtml($version);

        $beatToKey = [
            'your_property' => 'Beat 1 — Your Property',
            'sold'          => "Beat 2 — What's Happened Around You",
            'competition'   => "Beat 3 — What's On The Market Now",
            'recommendation'=> 'Beat 4 — Where You Should Be',
            'waiting'       => 'Beat 5 — What Waiting Costs',
        ];

        foreach ($beatToKey as $beatKey => $label) {
            $expectedSection = $payload['section_index'][$beatKey];
            $needle = "Section {$expectedSection} · {$label}";
            $this->assertStringContainsString(
                $needle,
                $html,
                "Beat banner for '{$beatKey}' must read 'Section {$expectedSection} · {$label}' to match the bullet's → p.N ref. Bullet ref drift = broken cross-reference."
            );
        }
    }

    public function test_seller_facing_jargon_is_stripped_from_beat_pages(): void
    {
        [$presentation, $version] = $this->seedFullDataSubject();

        $html = (new PresentationPdfService())->buildHtml($version);

        // Spec §1 — these seller-facing phrases must NOT survive on the
        // beat pages or the Executive Summary. The appendix (Inflow &
        // Absorption / PropCon / Pricing Scenarios) keeps its technical
        // terms because it lives outside the five-beat reading flow; the
        // assertions below target labels that historically only appeared
        // on seller-facing surfaces.
        $forbidden = [
            'Median Sale Price'  => 'Beat 2 Suburb Price Summary table label',
            'R/m²'               => 'Recent Sales / CMA Comps column header',
            'months of supply'   => 'Beat 2 gauge subtitle + Beat 3 Stock Absorption callout',
            'Stock Absorption:'  => 'Beat 3 active-comp callout strong label',
            'Your Price Position:' => 'Beat 3 price-position callout strong label',
            'th percentile'      => 'Beat 3 price-position percentile suffix',
            'Suburb Median'      => 'Beat 4 Why-This-Range row label',
            'Price Position Analysis' => 'Beat 4 strategy table h3',
            'Market Absorption Gauge' => 'Beat 2 gauge eyebrow label',
        ];

        foreach ($forbidden as $needle => $where) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "Seller-facing jargon survived: '{$needle}' (was at: {$where}). Spec §1 forbids this phrase on the five beat pages and Exec Summary."
            );
        }
    }

    // ── seed harness ─────────────────────────────────────────────────────

    private function seedFullDataSubject(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $property = Property::create([
            'agency_id'     => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title'         => 'Subject House', 'property_type' => 'House',
            'category'      => 'Residential', 'suburb' => 'Uvongo',
            'price'         => 1_800_000, 'beds' => 3, 'baths' => 2,
            'address'       => '4 Tucker Avenue, Uvongo',
            'status'        => 'active', 'listing_type' => 'sale',
            'erf_size_m2'   => 150, 'title_type' => 'full_title',
            'latitude'      => -30.84, 'longitude' => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id'        => $agencyId, 'branch_id' => $agencyId,
            'property_id'      => $property->id, 'created_by_user_id' => $user->id,
            'title'            => 'B2-fu3 Render Order',
            'property_address' => '4 Tucker Avenue, Uvongo',
            'suburb'           => 'Uvongo', 'property_type' => 'House',
            'bedrooms'         => 3, 'bathrooms' => 2,
            'asking_price_inc' => 1_800_000, 'erf_size_m2' => 150,
            'status'           => 'draft', 'currency' => 'ZAR',
            'monthly_rates'    => 800, 'monthly_insurance' => 200,
            'monthly_utilities'=> 1200, 'monthly_garden' => 1500,
            'monthly_pool'     => 2000, 'monthly_security' => 2500,
            'monthly_opportunity_cost' => 300, 'monthly_bond' => 0,
        ]);
        $version = PresentationVersion::create([
            'agency_id'         => $agencyId, 'presentation_id' => $presentation->id,
            'compiled_by'       => $user->id, 'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_AWAITING,
            'ai_summary_text'   => null,
        ]);

        foreach (['cma.lower_range' => 1_500_000, 'cma.middle_range' => 1_700_000, 'cma.upper_range' => 1_900_000] as $key => $value) {
            PresentationField::create([
                'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
                'field_key' => $key, 'final_value' => (string) $value,
            ]);
        }

        foreach ([1_500_000, 1_600_000, 1_700_000, 1_800_000, 1_900_000] as $i => $price) {
            PresentationSoldComp::create([
                'agency_id'       => $agencyId, 'presentation_id' => $presentation->id,
                'property_type'   => 'house', 'suburb' => 'Uvongo',
                'sold_price_inc'  => $price, 'sold_date' => now()->subMonths($i + 1)->toDateString(),
                'size_m2'         => 140 + $i * 10,
                'raw_row_json'    => json_encode(['address' => "Comp #{$i}", 'source' => 'vicinity_sales', 'extent_m2' => 140 + $i * 10]),
                'parser_version'  => 'test',
            ]);
        }

        foreach ([1_550_000, 1_650_000, 1_750_000, 1_850_000] as $i => $price) {
            DB::table('prospecting_listings')->insert([
                'agency_id'           => $agencyId, 'is_active' => 1,
                'captured_by_user_id' => $user->id,
                'portal_source'       => 'p24', 'portal_ref' => 'P24-' . Str::random(8),
                'portal_url'          => 'https://www.property24.com/' . Str::random(10),
                'address'             => "Competitor #{$i}", 'suburb' => 'Uvongo',
                'price'               => $price, 'bedrooms' => 3, 'bathrooms' => 2,
                'property_size_m2'    => 150, 'erf_size_m2' => 500,
                'property_type'       => 'House',
                'first_seen_at'       => now()->subDays(30 + $i * 20),
                'last_seen_at'        => now(),
                'created_at'          => now(), 'updated_at' => now(),
            ]);
        }

        return [$presentation, $version];
    }
}
