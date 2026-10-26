<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Domain\Presentation\TextExtractionService;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\MarketReports\MarketDataDiscrepancy;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\User;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\MarketReports\Parsers\CmaInfoMedianSalesAnalysisParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Yearly figures carry their year (mic-complete-spec §8.9.2, 26 Oct 2026). A LOOM report's spot check flagged two
 * "mismatches": the 2017 and 2018 median prices, audited against the 2025 median. The year was stored all along
 * (metric_date = 31 Dec of the year); the audit never sent it and the report screen's Date column read the wrong column.
 *
 *  1. Every producer of a yearly figure writes its year (known-format parser, AI read / learned layout via the mapper).
 *  2. The spot check audits a yearly figure against its own year only; a yearly figure with no year is "cannot audit".
 *  3. The report screen shows the year for yearly rows.
 */
final class ReportYearlyFiguresTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;
    private ?string $nextFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        config()->set('services.anthropic.usd_to_zar', 16.5);

        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Alpha ' . Str::random(4), 'slug' => 'alpha-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->userId = (int) User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin'])->id;
    }

    // ── 1. every producer writes the year ────────────────────────────────

    public function test_which_metric_keys_are_yearly(): void
    {
        foreach (['suburb_median_price_year', 'suburb_average_price_year', 'suburb_sales_count_year', 'suburb_low_year', 'suburb_high_year', 'suburb_max_year', 'suburb_annual_change_pct'] as $key) {
            $this->assertTrue(MarketDataPoint::isYearlyMetric($key), $key);
        }
        foreach (['comparable_sale_price', 'cma_value_middle', 'suburb_median_price_12m', 'suburb_total_sales_12m', null] as $key) {
            $this->assertFalse(MarketDataPoint::isYearlyMetric($key), (string) $key);
        }
    }

    public function test_every_yearly_figure_the_ai_read_or_a_learned_layout_gives_carries_its_year(): void
    {
        $mapper = new CanonicalReadMapper();
        $truth = json_decode((string) file_get_contents(base_path('tests/Fixtures/market_reports/loom_cma.read.json')), true);
        $clean = $mapper->sanitise($truth)['clean'];
        // one figure of every yearly metric the mapper knows, so a newly added metric cannot slip through unnoticed
        $clean['suburb_stats'] = [];
        foreach (array_keys(CanonicalReadMapper::STAT_METRICS) as $metric) {
            if (!in_array($metric, ['median_price_12m', 'total_sales_12m'], true)) {
                $clean['suburb_stats'][] = ['metric' => $metric, 'year' => 2017, 'value' => 3_300_000];
            }
        }

        $report = new MarketReport(['source_suburb' => 'Blue Valley Golf Estate']);
        $points = $mapper->toParseResult($clean, $report)->dataPoints;

        $yearly = array_values(array_filter($points, fn ($p) => MarketDataPoint::isYearlyMetric($p['metric_key'])));
        $this->assertNotEmpty($yearly);
        foreach ($yearly as $p) {
            $this->assertSame('2017-12-31', $p['metric_date'], $p['metric_key'] . ' must carry its year, not today');
        }
    }

    public function test_a_yearly_figure_with_no_year_is_not_stored_with_an_invented_one(): void
    {
        $mapper = new CanonicalReadMapper();
        $out = $mapper->sanitise(['suburb_stats' => [['metric' => 'median_price', 'value' => 3_300_000]]]);

        $this->assertSame([], $out['clean']['suburb_stats'], 'no year → the figure is dropped, never dated today');
    }

    public function test_the_known_format_parser_writes_the_year_on_every_yearly_figure(): void
    {
        $text = <<<TXT
        ST Residential Sales Analysis
        Year      UVONGO            RAY NKONYENI
        Median Selling Price
        2025 5 R 1 300 000 10.64% 393.3
        2024 7 R 1 200 000 3.10% 380.0
        Residential Price Ranges
        Year Count Low Median High Maximum
        2025 7 R 1 295 000 R 1 300 000 R 1 325 000 R 1 700 000
        Please note these figures are indicative.
        TXT;
        $extractor = \Mockery::mock(TextExtractionService::class);
        $extractor->shouldReceive('extractText')->andReturn($text);
        $tmp = tempnam(sys_get_temp_dir(), 'mdr') . '.pdf';
        file_put_contents($tmp, $text);
        $result = (new CmaInfoMedianSalesAnalysisParser($extractor))->parse($tmp, new MarketReport(['source_suburb' => 'UVONGO']));
        @unlink($tmp);

        $yearly = array_values(array_filter($result->dataPoints, fn ($p) => MarketDataPoint::isYearlyMetric($p['metric_key'])));
        $this->assertNotEmpty($yearly);
        foreach ($yearly as $p) {
            $this->assertMatchesRegularExpression('/^20(24|25)-12-31$/', (string) ($p['metric_date'] ?? ''), $p['metric_key']);
        }
    }

    public function test_period_year_reads_the_year_off_a_stored_yearly_figure_only(): void
    {
        $report = $this->report();
        $yearly = $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        $noYear = $this->point($report, 'suburb_median_price_year', 4_450_000, '2026-10-26');   // read on this date, year unknown
        $other  = $this->point($report, 'comparable_sale_price', 1_500_000, '2017-12-31');

        $this->assertSame(2017, $yearly->fresh()->periodYear());
        $this->assertNull($noYear->fresh()->periodYear());
        $this->assertNull($other->fresh()->periodYear(), 'only yearly metrics have a period year');
    }

    // ── 2. the spot check audits the same year ───────────────────────────

    public function test_the_audit_is_told_which_year_the_figure_is_for(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => true, 'cannot_tell' => false, 'audit_value' => 3300000, 'severity' => 'low', 'notes' => 'ok'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'period_year') && str_contains($body, '2017');
        });
        $this->assertSame(MarketReport::SPOT_PASSED, $report->fresh()->spot_check_status);
    }

    public function test_a_chart_label_over_its_own_year_is_settled_from_the_page_without_asking_the_ai(): void
    {
        $this->withLoomPdfText(function () {
            $report = $this->report();
            $this->point($report, 'suburb_average_price_year', 4_580_000, '2024-12-31');   // "R 4.58 M" is printed over 2024
            $this->point($report, 'suburb_median_price_year', 4_000_000, '2021-12-31');    // "R4M" over 2021 (and as an axis label)
            $this->point($report, 'suburb_sales_count_year', 23, '2024-12-31');            // "23" over 2024
            Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => false, 'audit_value' => 'R 1 M', 'severity' => 'high', 'notes' => 'x'])]);

            SpotCheckMarketReportJob::dispatchSync($report->id);

            Http::assertNothingSent();
            $this->assertSame(MarketReport::SPOT_PASSED, $report->fresh()->spot_check_status);
            $this->assertSame(3, $report->fresh()->spot_check_results['points_checked']);
        });
    }

    public function test_a_figure_printed_over_a_different_year_is_not_settled_and_goes_to_the_ai_with_the_page(): void
    {
        $layout = (string) file_get_contents(base_path('tests/Fixtures/market_reports/loom_cma.layout.txt'));
        $this->assertStringNotContainsString('R 4.58 M', mb_substr($layout, 0, 8000), 'precondition: the chart label is beyond the old excerpt');
        $this->withLoomPdfText(function () {
            $report = $this->report();
            $this->point($report, 'suburb_average_price_year', 4_580_000, '2018-12-31');   // that label is over 2024, not 2018
            Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => false, 'audit_value' => 'R 3.6 M', 'severity' => 'medium', 'notes' => '2018 is 3.6 M'])]);

            SpotCheckMarketReportJob::dispatchSync($report->id);

            Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'period_year'));
            $this->assertSame(MarketReport::SPOT_FLAGGED, $report->fresh()->spot_check_status);
        });
    }

    /** Runs $body with a stored stand-in file whose layout text is the LOOM fixture. */
    private function withLoomPdfText(callable $body): void
    {
        $layout = (string) file_get_contents(base_path('tests/Fixtures/market_reports/loom_cma.layout.txt'));
        $this->mock(TextExtractionService::class, fn ($m) => $m->shouldReceive('extractText')->andReturn($layout));
        $this->withStoredFile(fn (string $relative) => $body());
    }

    private function withStoredFile(callable $body): void
    {
        $relative = 'market-reports/test/' . $this->agencyId . '/' . Str::random(12) . '.pdf';
        $absolute = \Illuminate\Support\Facades\Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, 'not a real pdf');
        $this->nextFile = $relative;
        try {
            $body($relative);
        } finally {
            @unlink($absolute);
            $this->nextFile = null;
        }
    }

    public function test_a_count_is_looked_for_as_a_chart_label_on_the_page_with_its_year_not_inside_a_date(): void
    {
        $datePage = "Sales\n  22 Jul 2024   R 3 900 000\n  23 Jul 2024   R 3 800 000\n";
        $chartPage = "Number of sales\n      23\n      2023    2024    2025\n  CHART-PAGE-MARKER\n";
        $layout = $datePage . "\f" . $chartPage;
        $this->mock(TextExtractionService::class, fn ($m) => $m->shouldReceive('extractText')->andReturn($layout));
        $relative = 'market-reports/test/' . $this->agencyId . '/' . Str::random(12) . '.pdf';
        $absolute = \Illuminate\Support\Facades\Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, 'not a real pdf');

        try {
            $report = $this->report();
            $report->update(['file_path' => $relative]);
            $this->point($report, 'suburb_sales_count_year', 23, '2024-12-31');
            Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => true, 'cannot_tell' => false, 'audit_value' => 23, 'severity' => 'low', 'notes' => 'ok'])]);

            SpotCheckMarketReportJob::dispatchSync($report->id);

            Http::assertSent(function ($request) {
                $body = json_encode($request->data());

                return str_contains($body, 'CHART-PAGE-MARKER') && !str_contains($body, '23 Jul 2024');
            });
        } finally {
            @unlink($absolute);
        }
    }

    public function test_an_auditor_that_calls_a_figure_a_mismatch_but_quotes_our_own_figure_has_agreed(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_average_price_year', 4_580_000, '2024-12-31');
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => false, 'audit_value' => 'R 4,580,000', 'severity' => 'low', 'notes' => 'x'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        $this->assertSame(0, MarketDataDiscrepancy::query()->where('report_id', $report->id)->count());
        $this->assertSame(MarketReport::SPOT_PASSED, $report->fresh()->spot_check_status);
    }

    public function test_a_yearly_figure_with_no_year_is_cannot_audit_not_a_value_mismatch(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_median_price_year', 3_300_000, '2026-10-26');   // dated the day it was read: no year
        // even an auditor that would call this a mismatch is never asked
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => false, 'audit_value' => 'R 4,450,000', 'severity' => 'high', 'notes' => 'x'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        Http::assertNothingSent();
        $report->refresh();
        $this->assertSame(0, MarketDataDiscrepancy::query()->where('report_id', $report->id)->count());
        $this->assertSame(MarketReport::SPOT_PASSED, $report->spot_check_status);
        $this->assertSame(0, $report->spot_check_results['discrepancies']);
        $this->assertSame(1, $report->spot_check_results['cannot_audit']);
        $this->assertSame('no year', $report->spot_check_results['cannot_audit_points'][0]['reason']);
    }

    public function test_an_auditor_that_cannot_tie_the_figure_to_its_year_is_not_a_mismatch(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => true, 'audit_value' => 'R 4,450,000', 'severity' => 'high', 'notes' => 'chart labels'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        $report->refresh();
        $this->assertSame(0, MarketDataDiscrepancy::query()->where('report_id', $report->id)->count());
        $this->assertSame(1, $report->spot_check_results['cannot_audit']);
    }

    public function test_a_real_mismatch_in_the_same_year_is_still_flagged(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => false, 'cannot_tell' => false, 'audit_value' => 'R 3,030,000', 'severity' => 'medium', 'notes' => '2017 median is 3 030 000'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        $this->assertSame(MarketReport::SPOT_FLAGGED, $report->fresh()->spot_check_status);
        $this->assertSame(1, MarketDataDiscrepancy::query()->where('report_id', $report->id)->count());
    }

    public function test_a_re_run_replaces_the_earlier_runs_open_findings_but_keeps_resolved_ones(): void
    {
        $report = $this->report();
        $p = $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        foreach ([false, true] as $resolved) {
            MarketDataDiscrepancy::create(['report_id' => $report->id, 'data_point_id' => $p->id, 'parsed_value' => '3300000', 'audit_value' => 'R 4,450,000',
                'discrepancy_type' => 'value_mismatch', 'severity' => 'high', 'resolved' => $resolved]);
        }
        Http::fake(['https://api.anthropic.com/*' => $this->auditAnswer(['agrees' => true, 'cannot_tell' => false, 'audit_value' => 3300000, 'severity' => 'low', 'notes' => 'ok'])]);

        SpotCheckMarketReportJob::dispatchSync($report->id);

        $left = MarketDataDiscrepancy::withoutGlobalScopes()->where('report_id', $report->id)->whereNull('deleted_at')->get();
        $this->assertCount(1, $left);
        $this->assertTrue((bool) $left[0]->resolved);
    }

    // ── 3. the report screen shows the year ──────────────────────────────

    public function test_the_report_screen_shows_the_year_for_yearly_rows_and_the_date_for_date_rows(): void
    {
        $report = $this->report();
        $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        $this->point($report, 'suburb_median_price_year', 3_580_000, '2026-10-26');

        $html = $this->actingAs(User::find($this->userId))
            ->get(route('market-intelligence.reports.show', $report))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#>\s*3,300,000\.00\s*</td>\s*<td[^>]*>\s*2017\s*</td>#', $html, 'yearly row shows its year in the Date column');
        $this->assertMatchesRegularExpression('#>\s*3,580,000\.00\s*</td>\s*<td[^>]*>\s*—\s*</td>#u', $html, 'a figure with no year shows a dash');
    }

    public function test_the_discrepancies_page_names_the_year(): void
    {
        $report = $this->report();
        $p = $this->point($report, 'suburb_median_price_year', 3_300_000, '2017-12-31');
        MarketDataDiscrepancy::create(['report_id' => $report->id, 'data_point_id' => $p->id, 'parsed_value' => '3300000', 'audit_value' => 'x',
            'discrepancy_type' => 'value_mismatch', 'severity' => 'medium', 'resolved' => false]);

        $this->actingAs(User::find($this->userId))
            ->get(route('market-intelligence.reports.discrepancies', $report))
            ->assertOk()
            ->assertSee('suburb_median_price_year · 2017', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function report(): MarketReport
    {
        return MarketReport::create([
            'agency_id' => $this->agencyId, 'uploaded_by_user_id' => $this->userId, 'report_type_id' => null,
            'file_path' => $this->nextFile ?? 'market-reports/test-' . Str::random(8) . '.pdf', 'file_name' => 'Yearly ' . Str::random(6) . '.pdf',
            'file_hash' => hash('sha256', Str::random(64)), 'report_date' => now()->toDateString(),
            'parse_status' => MarketReport::PARSE_PARSED, 'spot_check_status' => MarketReport::SPOT_PENDING, 'data_points_count' => 1,
        ]);
    }

    private function point(MarketReport $report, string $key, float $value, string $metricDate): MarketDataPoint
    {
        return MarketDataPoint::create([
            'agency_id' => $this->agencyId, 'report_id' => $report->id, 'suburb_normalised' => 'blue valley golf estate',
            'metric_key' => $key, 'metric_value_numeric' => $value, 'metric_date' => $metricDate,
            'confidence' => 'medium', 'source_type' => 'report',
        ]);
    }

    private function auditAnswer(array $json)
    {
        return Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage'   => ['input_tokens' => 900, 'output_tokens' => 60],
            'model'   => 'claude-haiku-4-5',
            'stop_reason' => 'end_turn',
        ], 200);
    }
}
