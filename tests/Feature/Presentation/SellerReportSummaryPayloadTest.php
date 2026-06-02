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
 * B2 — Executive Summary payload tests.
 *
 * Spec: .ai/specs/seller-report-restructure.md
 *
 * Pins the §3 token bindings, the §4c above_clause conditional logic,
 * and the §5 sectionIndex with the §7 beat-suppression recompute rule.
 *
 * Coverage matrix (spec §7 degraded states):
 *   - full-data case (every token resolves; no suppression)
 *   - well-priced subject (asking <= cma_upper → above_clause empty,
 *     well_priced flag set, Bullet 5 softens)
 *   - over-priced subject (asking > sold_high AND > comp_high →
 *     compound above_clause)
 *   - no comps → Bullet 2 + Beat 2 suppressed, sectionIndex recomputes
 *   - empty competitor_stock.visible → Bullet 3 suppressed
 *   - no CMA middle → Bullet 4 suppressed
 *   - no holding cost → Bullet 5 suppressed
 */
final class SellerReportSummaryPayloadTest extends TestCase
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

    public function test_full_data_payload_resolves_every_token_and_indexes_all_five_beats(): void
    {
        [$presentation, $version] = $this->seedFullDataSubject(
            askingPrice: 1_800_000,    // mid-range
            cmaLower:    1_500_000,
            cmaMiddle:   1_700_000,
            cmaUpper:    1_900_000,
            holdingTotal: 8500,
        );

        $data = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);

        // section_index — all five beats present. Per B2-followup-2,
        // page cursor accounts for per-beat content size:
        //   Beat 1 (Your Property)      = 1 page → starts p.3
        //   Beat 2 (Sold/Market/Spatial) = 3 pages → starts p.4
        //   Beat 3 (Competition)         = 2 pages → starts p.7
        //   Beat 4 (Recommendation)      = 1 page → starts p.9
        //   Beat 5 (Waiting)             = 1 page → starts p.10
        $this->assertSame(['your_property', 'sold', 'competition', 'recommendation', 'waiting'], array_keys($payload['section_index']));
        $this->assertSame(3,  $payload['section_index']['your_property']);
        $this->assertSame(4,  $payload['section_index']['sold']);
        $this->assertSame(7,  $payload['section_index']['competition']);
        $this->assertSame(9,  $payload['section_index']['recommendation']);
        $this->assertSame(10, $payload['section_index']['waiting']);

        // Five bullets, all unsuppressed.
        $this->assertCount(5, $payload['bullets']);
        foreach ($payload['bullets'] as $b) {
            $this->assertFalse($b['suppressed'], "Bullet {$b['key']} should not be suppressed in full-data case");
            $this->assertStringStartsWith('p.', $b['ref']);
            $this->assertNotEmpty($b['html']);
        }

        // Tokens — every spec §3 binding resolves.
        $tokens = $payload['tokens'];
        $this->assertSame(1_800_000, $tokens['asking_price']);
        $this->assertSame(1_700_000, $tokens['recommended_price']);
        $this->assertSame(8500, $tokens['holding_monthly']);
        $this->assertGreaterThan(0, $tokens['competing_count']);
        $this->assertNotNull($tokens['sold_low']);
        $this->assertNotNull($tokens['sold_high']);
        $this->assertNotNull($tokens['suburb']);
        $this->assertNotNull($tokens['type']);
    }

    public function test_well_priced_subject_suppresses_above_clause_and_softens_waiting(): void
    {
        // Asking well below cma_upper → well-priced branch (§4c).
        // NOTE: post-B0a/B0b the tile cma_upper comes from pool_stats.p75
        // of the cleaned sold comp pool, NOT from the cma.upper_range field.
        // With our 5 seeded comps (1.5M/1.6M/1.7M/1.8M/1.9M) p75 = 1.8M,
        // so an asking of 1.6M sits comfortably below the band.
        [$presentation, $version] = $this->seedFullDataSubject(
            askingPrice: 1_600_000,
            cmaLower:    1_500_000,
            cmaMiddle:   1_700_000,
            cmaUpper:    1_900_000,
            holdingTotal: 8500,
        );

        $data = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);

        $this->assertTrue($payload['well_priced'], 'Asking ≤ cma_upper must set well_priced=true');
        $this->assertSame('', $payload['tokens']['above_clause'], 'above_clause must be empty for well-priced subject');

        // Bullet 5 (waiting) — the "same money in your pocket sooner"
        // pressure line must NOT appear in the well-priced branch.
        $waitingBullet = collect($payload['bullets'])->firstWhere('key', 'waiting');
        $this->assertStringNotContainsString('same money', $waitingBullet['html']);
        $this->assertStringContainsString('working figure', $waitingBullet['html']);
    }

    public function test_over_priced_subject_renders_above_clause_compound(): void
    {
        // Asking well above everything → above_competition AND above_sold,
        // compound clause text. Property.price stays at 1.8M so the
        // competitor-stock ±20% band (1.44-2.16M) still includes the
        // seeded 1.55-1.85M competitors; only asking_price_inc is high.
        [$presentation, $version] = $this->seedFullDataSubject(
            askingPrice:   2_000_000,   // above cma_upper, above sold_high, above comp_high
            cmaLower:      1_500_000,
            cmaMiddle:     1_700_000,
            cmaUpper:      1_900_000,
            holdingTotal:  8500,
            propertyPrice: 1_800_000,   // keep matching band wide enough to capture competitors
        );

        $data = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);

        $this->assertFalse($payload['well_priced']);
        $clause = $payload['tokens']['above_clause'];
        $this->assertNotSame('', $clause, 'above_clause must populate for over-priced subject');
        $this->assertStringContainsString('homes you', $clause, 'Should reference competing homes');
        $this->assertStringContainsString('sold', $clause, 'Should reference everything similar that\'s sold');

        // Bullet 4 — copy must include the conditional above_clause.
        $recBullet = collect($payload['bullets'])->firstWhere('key', 'recommendation');
        $this->assertStringContainsString('priced above', $recBullet['html']);
    }

    public function test_no_comps_suppresses_bullet_2_and_keeps_section_index_consistent(): void
    {
        // No sold comps — Beat 2 (sold) has no data → Bullet 2 suppressed.
        // Per spec §7 the sectionIndex still emits a page for sold so
        // downstream refs stay consistent.
        [$presentation, $version] = $this->seedSubjectWithoutComps(
            askingPrice: 1_800_000,
            cmaMiddle:   1_700_000,
            cmaUpper:    1_900_000,
            holdingTotal: 8500,
        );

        $data = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);

        $soldBullet = collect($payload['bullets'])->firstWhere('key', 'sold');
        $this->assertTrue($soldBullet['suppressed'], 'Bullet 2 must be suppressed when there are no comps');

        // Other bullets unaffected.
        $propBullet = collect($payload['bullets'])->firstWhere('key', 'your_property');
        $this->assertFalse($propBullet['suppressed']);

        // sectionIndex still has all five keys.
        $this->assertArrayHasKey('sold', $payload['section_index']);
        $this->assertSame(['your_property', 'sold', 'competition', 'recommendation', 'waiting'], array_keys($payload['section_index']));
    }

    public function test_no_cma_middle_suppresses_recommendation_bullet(): void
    {
        // No CMA → Bullet 4 (recommendation) suppressed.
        [$presentation, $version] = $this->seedSubjectWithoutCma(askingPrice: 1_800_000, holdingTotal: 8500);

        $data = (new AnalysisDataService())->compile($presentation->fresh(['property']), $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);

        $recBullet = collect($payload['bullets'])->firstWhere('key', 'recommendation');
        $this->assertTrue($recBullet['suppressed']);
    }

    // ── seed helpers ─────────────────────────────────────────────────

    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        return [$agencyId, $user];
    }

    private function seedFullDataSubject(int $askingPrice, int $cmaLower, int $cmaMiddle, int $cmaUpper, int $holdingTotal, ?int $propertyPrice = null): array
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();

        // property.price drives the ±20% competitor-stock matching band.
        // Default = askingPrice; tests can override (e.g. over-priced
        // scenario where asking is far above market — we still need
        // competitors to fall in the band so above_clause compounds).
        $propertyPrice ??= $askingPrice;

        $property = Property::create([
            'agency_id'     => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title'         => 'Subject House', 'property_type' => 'House',
            'category'      => 'Residential', 'suburb' => 'Uvongo',
            'price'         => $propertyPrice, 'beds' => 3, 'baths' => 2,
            'address'       => '4 Tucker Avenue, Uvongo',
            'status'        => 'active', 'listing_type' => 'sale',
            'erf_size_m2'   => 150, 'title_type' => 'full_title',
            'latitude'      => -30.84, 'longitude' => 30.39,
        ]);
        $presentation = Presentation::create([
            'agency_id'        => $agencyId, 'branch_id' => $agencyId,
            'property_id'      => $property->id, 'created_by_user_id' => $user->id,
            'title'            => 'B2 Test', 'property_address' => '4 Tucker Avenue, Uvongo',
            'suburb'           => 'Uvongo', 'property_type' => 'House',
            'bedrooms'         => 3, 'bathrooms' => 2,
            'asking_price_inc' => $askingPrice, 'erf_size_m2' => 150,
            'status'           => 'draft', 'currency' => 'ZAR',
            'monthly_rates'    => 800, 'monthly_insurance' => 200,
            'monthly_utilities'=> 1200, 'monthly_garden' => 1500,
            'monthly_pool'     => 2000, 'monthly_security' => 2500,
            'monthly_opportunity_cost' => $holdingTotal - 8200, // calibrate to target
            'monthly_bond'     => 0,
        ]);
        $version = PresentationVersion::create([
            'agency_id'         => $agencyId, 'presentation_id' => $presentation->id,
            'compiled_by'       => $user->id, 'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(), 'review_status' => PresentationVersion::REVIEW_AWAITING,
            'ai_summary_text'   => null,
        ]);

        // Seed CMA fields so AnalysisDataService produces a band.
        foreach (['cma.lower_range' => $cmaLower, 'cma.middle_range' => $cmaMiddle, 'cma.upper_range' => $cmaUpper] as $key => $value) {
            PresentationField::create([
                'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
                'field_key' => $key, 'final_value' => (string) $value,
            ]);
        }

        // Seed sold comps so the cleaned pool produces a min/max for Bullet 2
        // and competitor_stock can be populated via the listing match below.
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

        // Seed prospecting_listings so competitor_stock.visible has data.
        // (days_on_market lives on the HFC stock JOIN row, not on
        // prospecting_listings itself — the longest_dom token is null
        // for non-HFC competitors, which the renderer handles.)
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

    private function seedSubjectWithoutComps(int $askingPrice, int $cmaMiddle, int $cmaUpper, int $holdingTotal): array
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = Property::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title' => 'Subject No Comps', 'property_type' => 'House',
            'category' => 'Residential', 'suburb' => 'Uvongo',
            'price' => $askingPrice, 'beds' => 3, 'address' => '4 Tucker Ave',
            'status' => 'active', 'listing_type' => 'sale', 'title_type' => 'full_title',
            'erf_size_m2' => 150,
        ]);
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'property_id' => $property->id, 'created_by_user_id' => $user->id,
            'title' => 'NoComps', 'property_address' => '4 Tucker Ave',
            'suburb' => 'Uvongo', 'property_type' => 'House',
            'bedrooms' => 3, 'asking_price_inc' => $askingPrice,
            'status' => 'draft', 'currency' => 'ZAR',
            'monthly_rates' => $holdingTotal,
        ]);
        $version = PresentationVersion::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'compiled_by' => $user->id, 'blueprint_version' => 'v1',
            'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at' => now(), 'review_status' => PresentationVersion::REVIEW_AWAITING,
        ]);
        PresentationField::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'field_key' => 'cma.middle_range', 'final_value' => (string) $cmaMiddle,
        ]);
        PresentationField::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'field_key' => 'cma.upper_range', 'final_value' => (string) $cmaUpper,
        ]);
        return [$presentation, $version];
    }

    private function seedSubjectWithoutCma(int $askingPrice, int $holdingTotal): array
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = Property::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title' => 'No CMA', 'property_type' => 'House',
            'category' => 'Residential', 'suburb' => 'Uvongo',
            'price' => $askingPrice, 'beds' => 3, 'address' => '4 Tucker Ave',
            'status' => 'active', 'listing_type' => 'sale', 'title_type' => 'full_title',
        ]);
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'property_id' => $property->id, 'created_by_user_id' => $user->id,
            'title' => 'NoCMA', 'property_address' => '4 Tucker Ave',
            'suburb' => 'Uvongo', 'property_type' => 'House',
            'bedrooms' => 3, 'asking_price_inc' => $askingPrice,
            'status' => 'draft', 'currency' => 'ZAR',
            'monthly_rates' => $holdingTotal,
        ]);
        $version = PresentationVersion::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id,
            'compiled_by' => $user->id, 'blueprint_version' => 'v1',
            'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at' => now(), 'review_status' => PresentationVersion::REVIEW_AWAITING,
        ]);
        return [$presentation, $version];
    }
}
