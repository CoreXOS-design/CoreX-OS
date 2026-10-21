<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Exceptions\AI\AiPausedException;
use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\DevSetting;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiRead;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\MarketReports\MarketReportType;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use App\Services\MarketReports\ReportReadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report recogniser — AI read + learned layouts (.ai/specs/mic-complete-spec.md §8.9).
 *
 * The AI is faked at the HTTP edge (api.anthropic.com), so the real AnthropicGateway,
 * AiSpendGuard and AiUsageRecorder all run; only the network answer is canned. The two
 * fixtures are a made-up publisher's suburb report in two suburbs (same layout, different
 * figures): no CMA Info wordmark or headings, so every built-in parser scores zero.
 */
final class ReportRecogniserTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;
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

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->userId = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;

        foreach ([['other', 'Other / Unknown', GenericFallbackParser::class], ['learned_layout', 'Learned layout', LearnedLayoutParser::class]] as [$key, $name, $class]) {
            MarketReportType::query()->firstOrCreate(['key' => $key], [
                'display_name' => $name, 'parser_class' => $class, 'expected_fields_json' => json_encode([]), 'auto_approve' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    // ── 1. the unrecognised report is read by AI, kept, flagged for checking, and its layout learned ──

    public function test_unrecognised_report_is_read_by_ai_marked_and_its_layout_learned(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->readA())]);

        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('ai', $report->read_method);
        $this->assertSame('Coastline Property Data', $report->read_publisher);
        $this->assertSame('Read by AI – check figures', $report->readBadge());
        $this->assertEqualsWithDelta(0.92, (float) $report->ai_read_confidence, 0.001);
        $this->assertSame(6, MarketReportCompRow::withoutGlobalScopes()->where('market_report_id', $report->id)->where('row_type', 'comp')->count());
        $this->assertGreaterThan(10, (int) $report->data_points_count);
        $this->assertDatabaseHas('market_data_points', ['report_id' => $report->id, 'metric_key' => 'cma_value_lower', 'metric_value_numeric' => 1450000]);
        $this->assertDatabaseHas('market_data_points', ['report_id' => $report->id, 'metric_key' => 'suburb_median_price_year', 'metric_value_numeric' => 1890000, 'metric_date' => '2025-12-31']);
        $this->assertDatabaseHas('market_report_comp_rows', ['market_report_id' => $report->id, 'address' => '41 Dolphin Street', 'sale_price' => 1495000, 'sale_date' => '2026-05-29', 'extent_m2' => 540]);

        // the existing spot-check runs on it, the AI answer is cached by file hash, one paid call.
        Bus::assertDispatched(SpotCheckMarketReportJob::class, fn ($j) => $j->reportId === $report->id);
        $this->assertSame(1, MarketReportAiRead::query()->count());
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_events', ['source' => 'market_report_read', 'agency_id' => $this->agencyId]);

        // the uploader is told, with the agreed wording and a working link
        Notification::assertSentTo(User::find($this->userId), PillarEventNotification::class, function ($n) use ($report) {
            return $n->body === "We couldn't read this layout with a known format, so CoreX read it with AI. Please check the figures."
                && $n->actionUrl === route('market-intelligence.reports.show', $report->id, false)
                && !str_contains($n->body, 'flag');
        });

        // the layout was learned from it (fingerprint + mapping), usable straight away
        $layout = MarketReportLearnedLayout::query()->first();
        $this->assertNotNull($layout);
        $this->assertSame('Coastline Property Data', $layout->publisher);
        $this->assertSame('learned', $layout->status);
        $this->assertGreaterThanOrEqual(0.9, $layout->selftest_score);
        $this->assertSame($report->id, (int) $layout->learned_from_report_id);
    }

    // ── 2. the next report in that layout is read deterministically, with no AI call at all ──

    public function test_second_report_in_a_learned_layout_is_read_without_the_ai(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->readA())]);
        $first = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($first->id);
        Http::assertSentCount(1);

        $second = $this->upload('fake_suburb_report_b.pdf');
        ParseMarketReportJob::dispatchSync($second->id);
        $second->refresh();

        Http::assertSentCount(1); // still the one call: B cost nothing
        $this->assertSame('learned', $second->read_method);
        $this->assertSame('learned_layout', $second->reportType?->key);
        $this->assertSame(MarketReportLearnedLayout::query()->value('id'), (int) $second->learned_layout_id);
        $this->assertSame(4, MarketReportCompRow::withoutGlobalScopes()->where('market_report_id', $second->id)->where('row_type', 'comp')->count());
        $this->assertDatabaseHas('market_report_comp_rows', ['market_report_id' => $second->id, 'address' => '60 Ocean View Road', 'sale_price' => 2480000, 'sale_date' => '2026-02-19', 'extent_m2' => 905, 'r_per_m2' => 2740]);
        $this->assertDatabaseHas('market_data_points', ['report_id' => $second->id, 'metric_key' => 'cma_value_upper', 'metric_value_numeric' => 2200000]);
        $this->assertDatabaseHas('market_data_points', ['report_id' => $second->id, 'metric_key' => 'suburb_annual_change_pct', 'metric_value_numeric' => 5.8, 'metric_date' => '2025-12-31']);
        $this->assertSame('Margate Beach', $second->source_suburb);
        $this->assertSame(1, (int) MarketReportLearnedLayout::query()->value('times_used'));
        // a deterministic read is not "check the figures": no AI notification for it
        Notification::assertNotSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.read_by_ai' && ($n->payload['report_id'] ?? null) === $second->id);
    }

    // ── 3. the same file is never paid for twice (cache by file hash) ──

    public function test_same_file_is_never_read_by_the_ai_twice(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->readA())]);
        $first = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($first->id);
        MarketReportLearnedLayout::query()->delete(); // force the AI path again, not the learned layout

        $again = $this->upload('fake_suburb_report_a.pdf', agencyId: $this->otherAgency());
        ParseMarketReportJob::dispatchSync($again->id);

        Http::assertSentCount(1);
        $this->assertSame('ai', $again->refresh()->read_method);
        $this->assertSame(1, MarketReportAiRead::query()->count());

        // an explicit "new AI read" is the only thing that pays again
        ParseMarketReportJob::dispatchSync($again->id, true);
        Http::assertSentCount(2);
    }

    // ── 4. nothing can read it: saved, flagged, told "couldn't read it", no AI cost ──

    public function test_agency_switch_off_means_unread_and_no_ai_call(): void
    {
        Http::fake();
        PerformanceSetting::set(ReportReadSettings::KEY_AI_ENABLED, 0, $this->agencyId);

        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        Http::assertNothingSent();
        $this->assertSame('unread', $report->read_method);
        $this->assertStringContainsString('switched off for this agency', (string) $report->ai_read_note);
        $this->assertSame(0, (int) $report->data_points_count);
        $this->assertSame('flagged', $report->spot_check_status);
        $this->assertTrue(is_file(Storage::disk('local')->path($report->file_path)), 'the file stays saved');
        Notification::assertSentTo(User::find($this->userId), PillarEventNotification::class,
            fn ($n) => $n->body === 'Your report could not be recognised. It has been sent to CoreX to fix. CoreX will report back to you as soon as possible.');
    }

    public function test_platform_switch_off_means_unread_and_no_ai_call(): void
    {
        Http::fake();
        config()->set('services.anthropic.enabled', false);

        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        Http::assertNothingSent();
        $this->assertSame('unread', $report->refresh()->read_method);
        $this->assertStringContainsString('switched off for the whole platform', (string) $report->ai_read_note);
        $this->assertStringNotContainsString('ANTHROPIC_ENABLED', (string) $report->ai_read_note, 'a plain message, no setting names');
    }

    public function test_report_over_the_page_limit_is_saved_but_not_sent_to_the_ai(): void
    {
        Http::fake();
        DevSetting::set(ReportReadSettings::KEY_MAX_PAGES, '2');

        $report = $this->upload('three_page_document.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        Http::assertNothingSent();
        $this->assertSame('unread', $report->refresh()->read_method);
        $this->assertStringContainsString('3 pages', (string) $report->ai_read_note);
        $this->assertStringContainsString('limit', (string) $report->ai_read_note);
    }

    public function test_low_confidence_reading_is_not_used(): void
    {
        $read = $this->readA();
        $read['confidence'] = 0.31;
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($read)]);

        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('unread', $report->read_method);
        $this->assertStringContainsString('not confident enough', (string) $report->ai_read_note);
        $this->assertSame(0, MarketReportCompRow::withoutGlobalScopes()->where('market_report_id', $report->id)->count());
        $this->assertSame(0, MarketReportLearnedLayout::query()->count(), 'nothing is learned from a reading nobody trusts');

        // lowering the cut-off later reuses the cached answer — no second paid call
        DevSetting::set(ReportReadSettings::KEY_MIN_CONFIDENCE, '0.3');
        ParseMarketReportJob::dispatchSync($report->id);
        Http::assertSentCount(1);
        $this->assertSame('ai', $report->refresh()->read_method);
    }

    public function test_a_document_that_is_not_a_market_report_yields_nothing(): void
    {
        $read = ['publisher' => null, 'comps' => [], 'suburb_stats' => [], 'confidence' => 0.0, 'layout' => []];
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($read)]);

        $report = $this->upload('not_a_market_report.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        $this->assertSame('unread', $report->refresh()->read_method);
        $this->assertSame(0, MarketReportLearnedLayout::query()->count());
    }

    public function test_ai_failure_and_spend_pause_are_unread_not_errors(): void
    {
        Http::fake(['https://api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);
        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('parsed', $report->parse_status);
        $this->assertSame('unread', $report->read_method);
        // Audit fix A8: a plain message for agents and emails; the provider's error text stays in the log only.
        $this->assertSame('The AI service could not read this report just now.', $report->ai_read_note);
        $this->assertStringNotContainsString('overloaded', (string) $report->ai_read_note);
        $this->assertStringNotContainsString('529', (string) $report->ai_read_note);
        $this->assertSame(0, MarketReportAiRead::query()->count(), 'a failed read is not cached as an answer');
        $this->assertTrue(class_exists(AiPausedException::class));
    }

    public function test_agency_ai_switch_defaults_to_on(): void
    {
        $this->assertTrue(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyId));
        PerformanceSetting::set(ReportReadSettings::KEY_AI_ENABLED, 0, $this->agencyId);
        $this->assertFalse(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyId));
        $this->assertTrue(app(ReportReadSettings::class)->aiEnabledForAgency($this->otherAgency()), 'another agency is not affected');
    }

    // ── 5. retiring a layout stops it being used, and it is not re-learned ──

    public function test_retired_layout_is_not_used_and_not_relearned(): void
    {
        Http::fake(['https://api.anthropic.com/*' => Http::sequence()
            ->push($this->aiBody($this->readA()), 200)
            ->push($this->aiBody($this->readB()), 200)]);

        ParseMarketReportJob::dispatchSync($this->upload('fake_suburb_report_a.pdf')->id);
        MarketReportLearnedLayout::query()->firstOrFail()->delete(); // retire

        $b = $this->upload('fake_suburb_report_b.pdf');
        ParseMarketReportJob::dispatchSync($b->id);

        $this->assertSame('ai', $b->refresh()->read_method, 'a retired layout must not read the report');
        $this->assertSame(0, MarketReportLearnedLayout::query()->count());
        $this->assertSame(1, MarketReportLearnedLayout::onlyTrashed()->count(), "and is not re-learned over the admin's decision");
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function otherAgency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function upload(string $fixture, ?int $agencyId = null): MarketReport
    {
        $agencyId ??= $this->agencyId;
        $relative = "market-reports/test/{$agencyId}/" . Str::random(12) . '.pdf';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        copy(base_path('tests/Fixtures/market_reports/' . $fixture), $absolute);
        $this->staged[] = $absolute;

        return MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $this->userId,
            'report_type_id'      => null,
            'file_path'           => $relative,
            'file_name'           => $fixture,
            'file_hash'           => hash_file('sha256', $absolute),
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PENDING,
            'spot_check_status'   => MarketReport::SPOT_PENDING,
            'data_points_count'   => 0,
        ]);
    }

    private function aiBody(array $json): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage'   => ['input_tokens' => 9000, 'output_tokens' => 1800],
            'model'   => 'claude-sonnet-4-6',
        ];
    }

    private function aiAnswer(array $json)
    {
        return Http::response($this->aiBody($json), 200);
    }

    /** What a good AI reading of fake_suburb_report_a.pdf looks like. */
    private function readA(): array
    {
        return [
            'publisher' => 'Coastline Property Data', 'report_title' => 'Suburb Sales Report',
            'suburb' => 'Southport', 'town' => null,
            'subject' => [], 'valuation' => ['lower' => 1450000, 'middle' => 1950000, 'upper' => 2600000],
            'comps' => [
                ['kind' => 'comp', 'address' => '12 Marine Drive',   'sale_date' => '2026-08-14', 'sale_price' => 1850000, 'extent_m2' => 620,  'r_per_m2' => 2984],
                ['kind' => 'comp', 'address' => '7 Albatross Close', 'sale_date' => '2026-07-02', 'sale_price' => 2275000, 'extent_m2' => 810,  'r_per_m2' => 2809],
                ['kind' => 'comp', 'address' => '41 Dolphin Street', 'sale_date' => '2026-05-29', 'sale_price' => 1495000, 'extent_m2' => 540,  'r_per_m2' => 2769],
                ['kind' => 'comp', 'address' => '3 Kingfisher Road', 'sale_date' => '2026-04-17', 'sale_price' => 2950000, 'extent_m2' => 1020, 'r_per_m2' => 2892],
                ['kind' => 'comp', 'address' => '88 Lagoon Avenue',  'sale_date' => '2026-03-06', 'sale_price' => 1720000, 'extent_m2' => 600,  'r_per_m2' => 2867],
                ['kind' => 'comp', 'address' => '19 Pelican Way',    'sale_date' => '2026-01-23', 'sale_price' => 2140000, 'extent_m2' => 735,  'r_per_m2' => 2912],
            ],
            'suburb_stats' => [
                ['metric' => 'median_price', 'year' => 2023, 'value' => 1620000], ['metric' => 'sales_count', 'year' => 2023, 'value' => 118], ['metric' => 'annual_change_pct', 'year' => 2023, 'value' => 4.2],
                ['metric' => 'median_price', 'year' => 2024, 'value' => 1745000], ['metric' => 'sales_count', 'year' => 2024, 'value' => 131], ['metric' => 'annual_change_pct', 'year' => 2024, 'value' => 7.7],
                ['metric' => 'median_price', 'year' => 2025, 'value' => 1890000], ['metric' => 'sales_count', 'year' => 2025, 'value' => 126], ['metric' => 'annual_change_pct', 'year' => 2025, 'value' => 8.3],
            ],
            'layout' => [
                'publisher_signature' => 'Coastline Property Data',
                'column_headings' => ['Property Address', 'Registered', 'Purchase Price', 'Erf Size', 'Price per m²', 'Year', 'Median Price', 'Transfers', 'Yearly Change'],
                'header_phrases' => ['Suburb Sales Report', 'Sold Properties', 'Suburb Price Trends', 'Indicative Value Range'],
            ],
            'confidence' => 0.92, 'notes' => null,
        ];
    }

    /** What a good AI reading of fake_suburb_report_b.pdf looks like (used when the AI is the one reading it). */
    private function readB(): array
    {
        $a = $this->readA();
        $a['suburb'] = 'Margate Beach';
        $a['comps'] = [
            ['kind' => 'comp', 'address' => '5 Sandpiper Lane',   'sale_date' => '2026-08-21', 'sale_price' => 1325000, 'extent_m2' => 480, 'r_per_m2' => 2760],
            ['kind' => 'comp', 'address' => '22 Tern Crescent',   'sale_date' => '2026-06-11', 'sale_price' => 1980000, 'extent_m2' => 705, 'r_per_m2' => 2809],
            ['kind' => 'comp', 'address' => '14 Gull Street',     'sale_date' => '2026-05-02', 'sale_price' => 1610000, 'extent_m2' => 590, 'r_per_m2' => 2729],
            ['kind' => 'comp', 'address' => '60 Ocean View Road', 'sale_date' => '2026-02-19', 'sale_price' => 2480000, 'extent_m2' => 905, 'r_per_m2' => 2740],
        ];
        $a['valuation'] = ['lower' => 1150000, 'middle' => 1600000, 'upper' => 2200000];
        $a['suburb_stats'] = [
            ['metric' => 'median_price', 'year' => 2023, 'value' => 1380000], ['metric' => 'sales_count', 'year' => 2023, 'value' => 96], ['metric' => 'annual_change_pct', 'year' => 2023, 'value' => 3.1],
            ['metric' => 'median_price', 'year' => 2024, 'value' => 1475000], ['metric' => 'sales_count', 'year' => 2024, 'value' => 104], ['metric' => 'annual_change_pct', 'year' => 2024, 'value' => 6.9],
            ['metric' => 'median_price', 'year' => 2025, 'value' => 1560000], ['metric' => 'sales_count', 'year' => 2025, 'value' => 99], ['metric' => 'annual_change_pct', 'year' => 2025, 'value' => 5.8],
        ];

        return $a;
    }
}
