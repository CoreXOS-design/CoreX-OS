<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Domain\Presentation\TextExtractionService;
use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\MarketReports\MarketReportType;
use App\Models\Role;
use App\Models\User;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\MarketReports\LayoutLearner;
use App\Services\MarketReports\LearnedLayoutReader;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report recogniser, 26 Oct 2026 (mic-complete-spec §8.9, "Chart labels and two-line rows"): a LOOM comparative market
 * analysis was read by the AI at 0.87 (58 figures) but its layout was never saved — "the learned mapping reproduced only
 * 43% of the AI reading". Its 30 yearly figures are chart data labels (not table rows), its estimate is printed under its
 * own column headings, and its 25 sales take two lines each (row number + address above the figures) at two page scales.
 * The fixtures are the real report's text with the people removed (loom_cma.layout.txt) and the AI's real answer
 * (loom_cma.read.json); nothing here knows the publisher's name — a second, invented publisher proves the same.
 */
final class ReportChartLayoutTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    /** @var string[] */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        config()->set('services.anthropic.usd_to_zar', 16.5);
        Bus::fake([SpotCheckMarketReportJob::class]);
        Notification::fake();

        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false, 'sort_order' => 1]);
        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Alpha Realty', 'slug' => 'alpha-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'name' => 'Agent Anne']);

        foreach ([['other', GenericFallbackParser::class], ['learned_layout', LearnedLayoutParser::class]] as [$key, $class]) {
            MarketReportType::query()->firstOrCreate(['key' => $key], ['display_name' => $key, 'parser_class' => $class, 'expected_fields_json' => json_encode([]), 'auto_approve' => 0]);
        }
        // the PDF text comes from the fixture, whatever file is uploaded
        $this->mock(TextExtractionService::class, fn ($m) => $m->shouldReceive('extractText')->andReturn($this->layoutText()));
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    // ── the LOOM report ──────────────────────────────────────────────────

    public function test_the_ai_read_of_the_loom_report_saves_a_layout_that_reproduces_every_figure(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth())]);

        $report = $this->upload('one');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('ai', $report->read_method);
        $layout = MarketReportLearnedLayout::query()->where('learned_from_report_id', $report->id)->first();
        $this->assertNotNull($layout, 'an AI read that passes the self-test must save a layout');
        $this->assertGreaterThanOrEqual(0.9, (float) $layout->selftest_score);
        $this->assertSame('comps+suburb_stats+valuation', $layout->report_kind);

        // the three figure classes that used to be lost
        $types = array_column($layout->mapping['tables'], 'type');
        $this->assertContains('chart', $types, 'yearly figures printed as chart labels');
        $this->assertSame('below', collect($layout->mapping['scalars'])->firstWhere('field', 'valuation.lower')['placement'], 'estimate printed under its column heading');
        $comps = collect($layout->mapping['tables'])->firstWhere('type', 'comps');
        $this->assertSame(['field' => 'address', 'index' => true], $comps['lead'], 'address on the line above the figures, behind a row number');
        $this->assertCount(2, $comps['entries'][0]['cs'], 'the table is printed at two page scales');

        // the layout alone, on the same text, gives back exactly what the AI read
        $read = (new LearnedLayoutReader())->read($layout->mapping, $this->layoutText());
        $want = (new CanonicalReadMapper())->sanitise($this->truth())['clean'];
        $this->assertCount(25, $read['comps']);
        $this->assertCount(30, $read['suburb_stats']);
        $this->assertSame($want['valuation']['lower'], $read['valuation']['lower']);
        $this->assertSame($want['valuation']['middle'], $read['valuation']['middle']);
        $this->assertSame($want['valuation']['upper'], $read['valuation']['upper']);
        $stat = fn (array $rows) => collect($rows)->mapWithKeys(fn ($s) => [$s['metric'] . '|' . $s['year'] => round((float) $s['value'], 2)])->sortKeys()->all();
        $this->assertSame($stat($want['suburb_stats']), $stat($read['suburb_stats']));
        $row = fn (array $rows) => collect($rows)->map(fn ($c) => [$c['address'], $c['sale_price'], $c['sale_date'], $c['extent_m2'] ?? null])->sortBy(0)->values()->all();
        $this->assertSame($row($want['comps']), $row($read['comps']));
    }

    public function test_the_next_loom_report_is_read_by_the_layout_with_no_ai_call(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth())]);
        ParseMarketReportJob::dispatchSync($this->upload('one')->id);
        Http::assertSentCount(1);

        $second = $this->upload('two');   // other bytes, same layout: not the AI's per-file cache
        ParseMarketReportJob::dispatchSync($second->id);
        $second->refresh();

        Http::assertSentCount(1);   // the second report cost nothing
        $this->assertSame('learned', $second->read_method);
        $this->assertSame(25, (int) DB::table('market_report_comp_rows')->where('market_report_id', $second->id)->where('row_type', 'comp')->count());
        $this->assertSame('8 Carlyke Street', DB::table('market_report_comp_rows')->where('market_report_id', $second->id)->orderBy('row_index')->orderBy('id')->value('address'));
        // 25 rows + 30 yearly figures + 3 estimate figures, and the same count the AI read gave
        $this->assertSame(
            (int) MarketReport::query()->withoutGlobalScopes()->where('read_method', 'ai')->value('data_points_count'),
            (int) $second->data_points_count,
        );
    }

    // ── not LOOM-specific: an invented publisher with its own chart ───────

    public function test_any_publishers_chart_labels_and_headed_estimate_are_learned_the_same_way(): void
    {
        $text = $this->inventedPublisherText();
        $ai = [
            'publisher' => 'Tidewater Data', 'suburb' => 'Uvongo', 'town' => 'Margate', 'confidence' => 0.9,
            'comps' => [], 'valuation' => ['lower' => 1200000, 'middle' => 1350000, 'upper' => 1500000],
            'suburb_stats' => [
                ['metric' => 'median_price', 'year' => 2022, 'value' => 1100000], ['metric' => 'median_price', 'year' => 2023, 'value' => 1250000],
                ['metric' => 'median_price', 'year' => 2024, 'value' => 1400000], ['metric' => 'median_price', 'year' => 2025, 'value' => 1550000],
                ['metric' => 'sales_count', 'year' => 2022, 'value' => 31], ['metric' => 'sales_count', 'year' => 2023, 'value' => 28],
                ['metric' => 'sales_count', 'year' => 2024, 'value' => 40], ['metric' => 'sales_count', 'year' => 2025, 'value' => 36],
            ],
            'layout' => ['header_phrases' => ['Tidewater Market Report', 'Median Sale Price', 'Sales Per Year', 'Value Range'], 'column_headings' => [], 'publisher_signature' => 'Tidewater'],
        ];
        $clean = (new CanonicalReadMapper())->sanitise($ai)['clean'];

        $learned = app(LayoutLearner::class)->learn($text, $clean, $ai['layout']);

        $this->assertTrue($learned['ok'], $learned['reason'] ?? '');
        $this->assertGreaterThanOrEqual(0.9, $learned['score']);
        $chart = collect($learned['mapping']['tables'])->where('type', 'chart');
        $this->assertCount(2, $chart, 'one chart per axis, each found by its own heading');
        $this->assertEqualsCanonicalizing(['median_price', 'sales_count'], $chart->flatMap(fn ($t) => array_column($t['series'], 'metric'))->all());
    }

    public function test_a_chart_that_cannot_be_read_back_is_never_saved(): void
    {
        $text = $this->inventedPublisherText();
        $ai = [
            'publisher' => 'Tidewater Data', 'confidence' => 0.9, 'comps' => [], 'valuation' => [],
            'suburb_stats' => [['metric' => 'median_price', 'year' => 2022, 'value' => 9900000], ['metric' => 'median_price', 'year' => 2023, 'value' => 9800000],
                ['metric' => 'median_price', 'year' => 2024, 'value' => 9700000], ['metric' => 'median_price', 'year' => 2025, 'value' => 9600000]],   // printed nowhere
            'layout' => ['header_phrases' => ['Tidewater Market Report', 'Median Sale Price', 'Sales Per Year', 'Value Range'], 'column_headings' => [], 'publisher_signature' => 'Tidewater'],
        ];
        $clean = (new CanonicalReadMapper())->sanitise($ai)['clean'];

        $learned = app(LayoutLearner::class)->learn($text, $clean, $ai['layout']);

        $this->assertFalse($learned['ok'], 'figures the text does not carry are not learnable');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A made-up publisher: two charts (labels are lone lines at the year's column) and a headed estimate. */
    private function inventedPublisherText(): string
    {
        $rows = [
            'Tidewater Market Report', '',
            'Median Sale Price', '',
            '                                                      R 1.55 M', '',
            '                                       R 1.4 M', '',
            '                        R 1.25 M', '',
            '          R 1.1 M', '',
            '   R2M                                                                R0',
            '          2022            2023            2024            2025', '',
            'Sales Per Year', '',
            '                                       40', '',
            '          31', '',
            '                                                      36', '',
            '                        28', '',
            '   50                                                                  0',
            '          2022            2023            2024            2025', '',
            'Value Range', '',
            'Lower                      Middle                    Upper',
            'R 1,200,000                R 1,350,000               R 1,500,000', '',
        ];

        return implode("\n", $rows);
    }

    private function layoutText(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/market_reports/loom_cma.layout.txt'));
    }

    /** @return array<string, mixed> */
    private function truth(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/market_reports/loom_cma.read.json')), true);
    }

    /** A stand-in file (the text is injected above); $variant makes the bytes differ so the AI's per-file cache cannot answer. */
    private function upload(string $variant): MarketReport
    {
        $relative = "market-reports/test/{$this->agencyId}/" . Str::random(12) . '.pdf';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, "not a real pdf: {$variant}");
        $this->staged[] = $absolute;

        return MarketReport::create([
            'agency_id' => $this->agencyId, 'uploaded_by_user_id' => $this->agent->id, 'report_type_id' => null,
            'file_path' => $relative, 'file_name' => "FullCMA_{$variant}.pdf", 'file_hash' => hash_file('sha256', $absolute),
            'report_date' => now()->toDateString(), 'parse_status' => MarketReport::PARSE_PENDING,
            'spot_check_status' => MarketReport::SPOT_PENDING, 'data_points_count' => 0,
        ]);
    }

    private function aiAnswer(array $json)
    {
        return Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage'   => ['input_tokens' => 9000, 'output_tokens' => 1800],
            'model'   => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
        ], 200);
    }
}
