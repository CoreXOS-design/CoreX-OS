<?php

declare(strict_types=1);

namespace Tests\Feature\MarketAnalytics;

use App\Models\User;
use App\Services\MarketAnalytics\Adapters\MarketCompRowsSoldAdapter;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-08-20 — live incident: Shawn's presentation for 138 Torquay Avenue,
 * Leisure Bay showed zero comparable sales despite Johan importing 4 real
 * CMA Info reports with 36 valid comp rows near the subject property. Root
 * cause: applyPropertyTypeFilter() only recognised the literal string
 * "residence" as a residential match. The vicinity-sale parser tags rows
 * "Residential" (a different label for the same erf-usage concept) — 25% of
 * all comp rows agency-wide (308/1225) — so every one of them was silently
 * invisible to a "house" or "unit" search.
 */
final class MarketCompRowsSoldAdapterPropertyTypeTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT_LAT = -31.0235791;
    private const SUBJECT_LNG = 30.2421621;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = DB::table('agencies')->insertGetId([
            'name'       => 'Test Agency '.uniqid(),
            'slug'       => 'test-agency-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertReport(): int
    {
        $user = User::factory()->create(['agency_id' => $this->agencyId]);

        return DB::table('market_reports')->insertGetId([
            'agency_id'            => $this->agencyId,
            'uploaded_by_user_id'  => $user->id,
            'file_path'            => 'market-reports/test.pdf',
            'file_name'            => 'test.pdf',
            'file_hash'            => hash('sha256', uniqid('', true)),
            'report_date'          => now()->toDateString(),
            'parse_status'         => 'parsed',
            'data_points_count'    => 1,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    private function insertCompRow(int $reportId, ?string $propertyType, array $overrides = []): void
    {
        DB::table('market_report_comp_rows')->insert(array_merge([
            'market_report_id'  => $reportId,
            'agency_id'         => $this->agencyId,
            'row_type'          => 'comp',
            'property_type'     => $propertyType,
            'sale_date'         => now()->subMonths(3)->toDateString(),
            'sale_price'        => 1000000,
            'latitude'          => self::SUBJECT_LAT,
            'longitude'         => self::SUBJECT_LNG,
            'suburb_normalised' => 'leisure bay',
            'is_demo'           => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $overrides));
    }

    private function filterFor(string $type): SoldTransactionsFilter
    {
        return new SoldTransactionsFilter(
            suburbSlug: 'leisure-bay',
            propertyType: $type,
            dateFrom: now()->subMonths(12)->toDateString(),
            dateTo: now()->toDateString(),
            compScope: SoldTransactionsFilter::SCOPE_RADIUS_ALL,
            compRadiusM: 1000,
            subjectLatitude: self::SUBJECT_LAT,
            subjectLongitude: self::SUBJECT_LNG,
            subjectIsDemo: false,
            agencyId: $this->agencyId,
        );
    }

    public function test_residential_row_is_returned_by_a_house_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Residential');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));

        $this->assertCount(1, $recs, 'A row tagged "Residential" must be visible to a house search.');
    }

    public function test_residence_row_still_returned_by_a_house_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Residence');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));

        $this->assertCount(1, $recs, 'Existing "Residence" behaviour must not regress.');
    }

    public function test_case_and_whitespace_variants_are_normalised(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, ' RESIDENTIAL ');
        $this->insertCompRow($reportId, 'residence');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));

        $this->assertCount(2, $recs, 'Casing and surrounding whitespace must not affect the match.');
    }

    public function test_commercial_row_excluded_from_house_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Commercial');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));

        $this->assertCount(0, $recs, 'Widening residential matching must not pull commercial rows into a house search.');
    }

    public function test_vacant_land_row_excluded_from_house_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Vacant Land');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));

        $this->assertCount(0, $recs, 'Vacant land must not appear in a house search.');
    }

    public function test_vacant_land_row_returned_by_a_land_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Vacant Land');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('land'));

        $this->assertCount(1, $recs, 'Vacant land must still be visible to a land search.');
    }

    public function test_residential_row_excluded_from_land_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, 'Residential');
        $this->insertCompRow($reportId, 'Residence');

        $recs = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('land'));

        $this->assertCount(0, $recs, 'Residential rows must not leak into a land search (pre-existing bug this fix also closes).');
    }

    public function test_unlabelled_row_still_passes_through_any_search(): void
    {
        $reportId = $this->insertReport();
        $this->insertCompRow($reportId, null);

        $house = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('house'));
        $land  = (new MarketCompRowsSoldAdapter())->getRecords($this->filterFor('land'));

        $this->assertCount(1, $house);
        $this->assertCount(1, $land);
    }
}
