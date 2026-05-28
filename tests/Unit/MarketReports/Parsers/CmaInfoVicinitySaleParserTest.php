<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketReports\Parsers\CmaInfoVicinitySaleParser;
use Tests\TestCase;

/**
 * Real-fixture tests for the Vicinity Sale parser. Fixtures live in
 * tests/Fixtures/market_reports/ — see the README there for the
 * conventions. Each test markTestSkipped() if its fixture is missing so
 * the suite still runs cleanly on a fresh checkout.
 *
 * This file establishes the testing pattern for the OTHER 4 CmaInfo
 * parsers (Market Analysis, Median Sales Analysis, Property Valuation,
 * Sectional Title Sales, Scheme Owners List) — they can be retro-fitted
 * by copying this file's structure.
 *
 * Tests are unit-style — they read a fixture file, hand it to the parser,
 * and assert on the returned MarketReportParseResult DTO. No DB writes
 * (the parser is pure per the MarketReportParser contract); the
 * orchestrating ParseMarketReportJob handles persistence and TP
 * match-or-create separately.
 */
final class CmaInfoVicinitySaleParserTest extends TestCase
{
    private const FIXTURE_RESIDENTIAL  = 'cma_info_vicinity_sale_residential.pdf';
    private const FIXTURE_VACANT_LAND  = 'cma_info_vicinity_sale_vacant_land.pdf';
    private const FIXTURE_SECTIONAL_TITLE = 'cma_info_sectional_title_sales.pdf';

    private function parser(): CmaInfoVicinitySaleParser
    {
        return app(CmaInfoVicinitySaleParser::class);
    }

    private function fixturePath(string $name): string
    {
        return base_path('tests/Fixtures/market_reports/' . $name);
    }

    private function requireFixture(string $name): string
    {
        $path = $this->fixturePath($name);
        if (!is_file($path)) {
            $this->markTestSkipped("Fixture missing: tests/Fixtures/market_reports/{$name} — see fixtures README.");
        }
        return $path;
    }

    private function stubReport(?string $suburb = null, ?string $town = null): MarketReport
    {
        // Pure parser test — no DB writes. A non-persisted MarketReport
        // satisfies the parse() signature without RefreshDatabase.
        $report = new MarketReport();
        $report->source_suburb = $suburb;
        $report->source_town   = $town;
        return $report;
    }

    // ── Detection ────────────────────────────────────────────────────────

    public function test_can_parse_returns_high_for_residential_vicinity_pdf(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $conf = $this->parser()->canParse($path);
        $this->assertGreaterThanOrEqual(0.6, $conf->score,
            "expected high confidence for residential vicinity, got {$conf->score} with reasons " . implode(', ', $conf->reasons));
        $this->assertContains('Residential sales within', $conf->reasons);
    }

    public function test_can_parse_returns_high_for_vacant_land_vicinity_pdf(): void
    {
        $path = $this->requireFixture(self::FIXTURE_VACANT_LAND);
        $conf = $this->parser()->canParse($path);
        $this->assertGreaterThanOrEqual(0.6, $conf->score,
            "expected high confidence for vacant land vicinity, got {$conf->score} with reasons " . implode(', ', $conf->reasons));
        $this->assertContains('Vacant land sales within', $conf->reasons);
    }

    public function test_can_parse_returns_none_for_sectional_title_pdf(): void
    {
        $path = $this->requireFixture(self::FIXTURE_SECTIONAL_TITLE);
        $conf = $this->parser()->canParse($path);
        $this->assertSame(0.0, $conf->score,
            "vicinity parser must yield to CmaInfoSectionalTitleSalesParser on sectional title fixtures");
        $this->assertContains('handled by CmaInfoSectionalTitleSalesParser', $conf->reasons);
    }

    public function test_can_parse_returns_none_for_nonexistent_file(): void
    {
        $conf = $this->parser()->canParse(base_path('tests/Fixtures/market_reports/does_not_exist.pdf'));
        $this->assertSame(0.0, $conf->score);
    }

    // ── Extraction — residential variant ──────────────────────────────────

    public function test_parse_residential_extracts_comp_rows(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport(suburb: 'MARGATE BEACH'));

        $this->assertGreaterThan(0, count($result->compRows),
            'residential vicinity must extract at least one comp row');

        // Every comp row is of comp type + carries suburb_normalised.
        foreach ($result->compRows as $row) {
            $this->assertSame(MarketReportCompRow::ROW_COMP, $row['row_type']);
            $this->assertSame('margate beach', $row['suburb_normalised']);
            $this->assertNull($row['scheme_name'], 'vicinity reports are freehold — no scheme_name');
            $this->assertNull($row['section_number'], 'vicinity reports are freehold — no section_number');
        }
    }

    public function test_parse_residential_populates_distance_to_subject_metres(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport());

        $withDistance = array_filter(
            $result->compRows,
            fn ($r) => isset($r['distance_to_subject_m']) && $r['distance_to_subject_m'] !== null,
        );
        $this->assertGreaterThan(0, count($withDistance),
            'at least one comp row must carry a populated distance_to_subject_m '
            . '(the sectional title parser leaves this null; vicinity reports populate it)');

        // Sanity: distances should be non-negative integers within the radius.
        foreach ($withDistance as $r) {
            $this->assertIsInt($r['distance_to_subject_m']);
            $this->assertGreaterThan(0, $r['distance_to_subject_m']);
            $this->assertLessThan(10_000, $r['distance_to_subject_m'],
                'vicinity radius is typically <= 2000m; values >10km suggest a parse error');
        }
    }

    public function test_parse_residential_captures_subject_metadata(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport());

        $this->assertSame('residential', $result->subjectMeta['subject_property_type'] ?? null);
        $this->assertArrayHasKey('radius_metres', $result->subjectMeta);
        $this->assertGreaterThan(0, $result->subjectMeta['radius_metres']);
    }

    public function test_parse_residential_captures_radius_as_data_point(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport());

        $radiusPoints = array_filter(
            $result->dataPoints,
            fn ($p) => ($p['metric_key'] ?? null) === 'vicinity_radius_metres',
        );
        $this->assertCount(1, $radiusPoints, 'exactly one vicinity_radius_metres data point');
    }

    public function test_parse_residential_persists_summary_stats(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport());

        $keys = array_column($result->dataPoints, 'metric_key');
        // Lower/middle/upper triplet from the existing parser convention.
        $this->assertContains('cma_value_lower', $keys);
        $this->assertContains('cma_value_middle', $keys);
        $this->assertContains('cma_value_upper', $keys);
        // The two vicinity-sale-specific extras.
        $this->assertContains('cma_value_average', $keys);
        $this->assertContains('cma_value_average_r_per_m2', $keys);
    }

    public function test_parse_residential_populates_extracted_addresses(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $result = $this->parser()->parse($path, $this->stubReport(suburb: 'MARGATE BEACH'));

        $this->assertGreaterThan(0, count($result->extractedAddresses),
            'extracted addresses feed TrackedPropertyMatchOrCreateService via the orchestrator '
            . '— at least one address per parsed comp row');
        // Each entry should carry a street_name (the orchestrator routes
        // these through match-or-create with source_type=cmainfo).
        foreach ($result->extractedAddresses as $addr) {
            $this->assertArrayHasKey('street_name', $addr);
        }
    }

    public function test_parse_residential_is_idempotent(): void
    {
        $path = $this->requireFixture(self::FIXTURE_RESIDENTIAL);
        $first  = $this->parser()->parse($path, $this->stubReport());
        $second = $this->parser()->parse($path, $this->stubReport());

        $this->assertSame(count($first->compRows), count($second->compRows),
            'comp row count must be stable across repeat calls');
        $this->assertSame(count($first->dataPoints), count($second->dataPoints),
            'data point count must be stable across repeat calls');
        $this->assertSame($first->subjectMeta, $second->subjectMeta);
    }

    // ── Extraction — vacant land variant ──────────────────────────────────

    public function test_parse_vacant_land_sets_subject_property_type(): void
    {
        $path = $this->requireFixture(self::FIXTURE_VACANT_LAND);
        $result = $this->parser()->parse($path, $this->stubReport());

        $this->assertSame('vacant_land', $result->subjectMeta['subject_property_type'] ?? null);
    }

    public function test_parse_vacant_land_extracts_comp_rows(): void
    {
        $path = $this->requireFixture(self::FIXTURE_VACANT_LAND);
        $result = $this->parser()->parse($path, $this->stubReport(suburb: 'HYDE PARK'));

        $this->assertGreaterThan(0, count($result->compRows),
            'vacant land vicinity must extract at least one comp row from the fixture');
    }
}
