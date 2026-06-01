<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AiSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AiSummaryService::gatherFacts — regression coverage for the HFC
 * neighbouring-sales join.
 *
 * Pre-fix the query did:
 *   Deal::withoutGlobalScopes()
 *       ->where('agency_id', $presentation->agency_id)
 *       ->join('properties', 'properties.id', '=', 'deals.property_id')
 *
 * Both `deals` and `properties` carry an `agency_id` column, so the
 * unqualified `where('agency_id', ...)` threw SQLSTATE[23000] —
 * "Column 'agency_id' in where clause is ambiguous". The collectHfc
 * branch only fires when the subject property has GPS, which the
 * existing CompetitorStockMatchTest seed deliberately omits — so the
 * failure had no test coverage. This test pins the join behaviour.
 */
final class AiSummaryGatherFactsTest extends TestCase
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

    public function test_gather_facts_with_subject_gps_runs_join_without_ambiguous_column_error(): void
    {
        [$presentation, $version, $agencyId, $subjectProperty] = $this->seedSubjectWithGps(
            lat: -30.83, lng: 30.39,
        );

        // Seed one nearby HFC deal (within 1km of subject) so the join
        // returns a row and the per-deal mapping runs end-to-end. Place
        // it ~150m east of the subject (well inside the 1km Haversine
        // ring + inside the bbox prefilter at ±0.01°).
        $neighbour = Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $presentation->created_by_user_id,
            'title'         => 'Neighbour',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'TestSuburb',
            'price'         => 1_950_000,
            'address'       => '2 Neighbour Way',
            'status'        => 'sold',
            'listing_type'  => 'sale',
            'latitude'      => -30.83,
            'longitude'     => 30.392,
        ]);

        $this->seedDeal($agencyId, $neighbour->id, saleDate: now()->subMonths(6)->toDateString(), salePrice: 1_950_000);

        // The join MUST execute cleanly — pre-fix this threw
        // SQLSTATE[23000] ambiguous column.
        $facts = (new AiSummaryService())->gatherFacts($presentation, $version);

        $this->assertArrayHasKey('hfc_neighbouring_sales', $facts);
        $hfc = $facts['hfc_neighbouring_sales'];
        $this->assertIsArray($hfc);
        $this->assertSame(1, $hfc['count']);
        $this->assertSame('2 Neighbour Way', $hfc['most_recent']['address']);
        $this->assertSame(1_950_000, $hfc['most_recent']['price']);
    }

    public function test_gather_facts_empty_neighbours_when_no_deals_within_1km(): void
    {
        // Subject has GPS but no neighbouring HFC deals — the join still
        // runs and returns empty cleanly. Verifies the no-rows path of
        // the fixed query.
        [$presentation, $version] = $this->seedSubjectWithGps(lat: -30.83, lng: 30.39);

        $facts = (new AiSummaryService())->gatherFacts($presentation, $version);

        $this->assertArrayHasKey('hfc_neighbouring_sales', $facts);
        $this->assertSame([], $facts['hfc_neighbouring_sales'],
            'No nearby deals → empty array (the prompt template silently omits the block).');
    }

    public function test_gather_facts_qualifies_agency_when_property_agency_differs_from_deal_agency(): void
    {
        // Pin the "deals.agency_id wins, not properties.agency_id"
        // intent. Seed a deal where the neighbouring PROPERTY belongs to
        // a different agency than the deal — confirms the qualified
        // prefix is doing the right thing (the deal's agency is the
        // filter, not the asset's current owner agency).
        [$presentation, $version, $agencyId, $subjectProperty] = $this->seedSubjectWithGps(
            lat: -30.83, lng: 30.39,
        );

        // Other agency owns this property today; HFC closed the deal
        // (deals.agency_id = HFC).
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(4),
            'slug' => 'other-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $otherAgencyId, 'agency_id' => $otherAgencyId, 'name' => 'Other Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgencyId, 'branch_id' => $otherAgencyId]);
        $neighbour = Property::withoutGlobalScopes()->create([
            'agency_id'     => $otherAgencyId,          // ← different from the deal
            'branch_id'     => $otherAgencyId,
            'agent_id'      => $otherAgent->id,
            'title'         => 'Cross-Agency Neighbour',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'TestSuburb',
            'price'         => 2_100_000,
            'address'       => '3 Mixed Way',
            'status'        => 'sold',
            'listing_type'  => 'sale',
            'latitude'      => -30.83,
            'longitude'     => 30.392,
        ]);

        // HFC (subject's agency) closed this deal.
        $this->seedDeal($agencyId, $neighbour->id, saleDate: now()->subMonths(3)->toDateString(), salePrice: 2_100_000);

        $facts = (new AiSummaryService())->gatherFacts($presentation, $version);
        $hfc = $facts['hfc_neighbouring_sales'];
        $this->assertSame(1, $hfc['count'],
            'Deal counts for the subject agency even when properties.agency_id differs.');
        $this->assertSame(2_100_000, $hfc['most_recent']['price']);
    }

    // ── renderFactsBlock unguarded-array-read class fixes ─────────────
    //
    // After array_filter strips null-valued keys at gatherFacts L154/155/
    // L173-178, renderFactsBlock used to do direct $arr['key'] reads in
    // three places (suburb year, cma lower/upper, agent agency_name) →
    // "Undefined array key" under strict error handling. These tests
    // exercise the rendered prompt directly with hand-crafted fact
    // arrays so every missing-key scenario is covered without staging
    // round-trips.

    public function test_render_facts_block_handles_suburb_with_name_but_no_year(): void
    {
        $facts = [
            'property' => ['address' => '1 Test Way'],
            // 'year' key DELIBERATELY absent — array_filter would strip
            // it when suburb.latest_year wasn't hydrated.
            'suburb'   => ['name' => 'Margate'],
        ];

        $out = $this->renderFactsBlock($facts);
        $this->assertStringContainsString('Suburb (Margate):', $out,
            'Suburb header still renders, year suffix silently omitted.');
        $this->assertStringNotContainsString('Margate ', $out);  // no trailing space before colon
    }

    public function test_render_facts_block_handles_cma_with_only_upper_bound(): void
    {
        $facts = [
            'property' => ['address' => '1 Test Way'],
            // 'lower' DELIBERATELY absent — common when the CMA report
            // has only an upper bound captured.
            'cma'      => ['upper' => 1_500_000],
        ];

        $out = $this->renderFactsBlock($facts);
        $this->assertStringContainsString('CMA Evaluation Range', $out,
            'CMA line still renders when only one bound is present.');
        $this->assertStringContainsString('R 1 500 000', $out);
    }

    public function test_render_facts_block_handles_cma_with_only_lower_bound(): void
    {
        $facts = [
            'property' => ['address' => '1 Test Way'],
            'cma'      => ['lower' => 1_200_000],
        ];
        $out = $this->renderFactsBlock($facts);
        $this->assertStringContainsString('CMA Evaluation Range', $out);
        $this->assertStringContainsString('R 1 200 000', $out);
    }

    public function test_render_facts_block_handles_agent_with_name_but_no_agency(): void
    {
        $facts = [
            'property' => ['address' => '1 Test Way'],
            // 'agency_name' DELIBERATELY absent.
            'agent'    => ['name' => 'Jane Agent'],
        ];

        $out = $this->renderFactsBlock($facts);
        $this->assertStringContainsString('Agent: Jane Agent', $out,
            'Agent line still renders, " at <agency>" suffix omitted when agency_name absent.');
        $this->assertStringNotContainsString(' at ', $out);
    }

    /**
     * Exercise the private renderFactsBlock via reflection — the bug
     * class lives inside this method and is most cheaply tested with
     * hand-crafted fact arrays (vs full presentation seeding for each).
     */
    private function renderFactsBlock(array $facts): string
    {
        $svc = new AiSummaryService();
        $rm = new \ReflectionMethod($svc, 'renderFactsBlock');
        $rm->setAccessible(true);
        return (string) $rm->invoke($svc, $facts);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array{0:Presentation, 1:PresentationVersion, 2:int, 3:Property} */
    private function seedSubjectWithGps(float $lat, float $lng): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'AiSum ' . Str::random(4),
            'slug' => 'aisum-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $property = Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'title'         => 'Subject',
            'property_type' => 'House',
            'category'      => 'Residential',
            'suburb'        => 'TestSuburb',
            'price'         => 2_000_000,
            'beds'          => 3,
            'address'       => '1 Subject Way',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => $lat,
            'longitude'     => $lng,
        ]);

        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $user->id,
            'title'              => 'AI summary regression',
            'property_address'   => $property->address,
            'suburb'             => $property->suburb,
            'property_type'      => $property->property_type,
            'asking_price_inc'   => 2_000_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        $version = PresentationVersion::create([
            'agency_id'          => $agencyId,
            'presentation_id'    => $presentation->id,
            'blueprint_version'  => 'test',
            'data_snapshot_json' => json_encode(['note' => 'ai-summary-regression']),
            'compiled_at'        => now(),
        ]);

        return [$presentation, $version, $agencyId, $property];
    }

    private function seedDeal(int $agencyId, int $propertyId, string $saleDate, int $salePrice): int
    {
        return (int) DB::table('deals')->insertGetId([
            'agency_id'       => $agencyId,
            'property_id'     => $propertyId,
            'period'          => substr($saleDate, 0, 7),  // 'YYYY-MM' for the period bucket
            'deal_date'       => $saleDate,
            'sale_date'       => $saleDate,
            'sale_price'      => $salePrice,
            'property_value'  => $salePrice,
            'total_commission'=> 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
