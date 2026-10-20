<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Listeners\MarketReports\NotifyOnMarketReportParseFailure;
use App\Models\DevSetting;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiUsage;
use App\Models\MarketReports\MarketReportLayoutCase;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\MarketReports\MarketReportType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\MarketReports\AiReportReadService;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use App\Services\MarketReports\ReportReadSettings;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit fixes for the market-report recogniser (2026-10-20, .ai/specs/mic-complete-spec.md §8.9):
 *  1. the paid AI read never runs inside a web request (queued; report shown as "being read");
 *  4. an agency over its AI limit holds only its own report (Johan, 20 Oct 2026) — other agencies still get the read;
 *  5. the team is alerted once per NEW layout case;
 *  6. an AI slot is used only when a request actually went to the AI;
 *  7. a release returns the slot to the row it was taken from;
 *  8. agents see a plain message, never the provider's error text.
 */
final class ReportAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;
    private User $owner;
    private bool $aiFails = false;
    /** @var array<string, mixed>|null */
    private ?array $aiJson = null;
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
        [$this->agencyId, $this->userId] = $this->agencyWithUser('Alpha Realty');
        $this->owner = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'name' => 'Owner One']);

        foreach ([['other', GenericFallbackParser::class], ['learned_layout', LearnedLayoutParser::class]] as [$key, $class]) {
            MarketReportType::query()->firstOrCreate(['key' => $key], ['display_name' => $key, 'parser_class' => $class, 'expected_fields_json' => json_encode([]), 'auto_approve' => 0]);
        }
        Http::fake(['https://api.anthropic.com/*' => fn () => $this->aiFails
            ? Http::response(['error' => 'overloaded: secret-provider-detail'], 529)
            : Http::response($this->aiBodyFor($this->aiJson ?? $this->readA()), 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        NotifyOnMarketReportParseFailure::$suppressed = false;
        foreach ($this->staged as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    // ── 1. the AI read is queued, never run inside the web request ──

    public function test_a_web_run_hands_the_ai_read_to_the_queue_and_leaves_the_report_being_read(): void
    {
        config()->set('queue.default', 'database'); // a real queue: the continuation is stored, not run inline
        $report = $this->upload();

        ParseMarketReportJob::dispatchSync($report->id, deferAi: true);

        Http::assertNothingSent(); // no paid call (and no lock wait) inside the request
        $report->refresh();
        $this->assertSame('parsing', $report->parse_status);
        $this->assertTrue($report->isBeingRead());
        $this->assertSame(0, $report->data_points_count);
        $queued = $this->queuedParseJobs();
        $this->assertCount(1, $queued);
        $this->assertSame($report->id, $queued[0]->reportId);
        $this->assertTrue($queued[0]->continuation);
        $this->assertFalse($queued[0]->deferAi);

        // the worker then does the real read
        app()->call([$queued[0], 'handle']);
        $report->refresh();
        Http::assertSentCount(1);
        $this->assertSame('parsed', $report->parse_status);
        $this->assertSame('ai', $report->read_method);
        $this->assertFalse($report->isBeingRead());
    }

    public function test_the_banner_tells_the_agent_the_report_is_being_read(): void
    {
        $report = $this->upload();
        $report->forceFill(['parse_status' => 'parsing', 'parse_started_at' => now()])->save();

        $this->actingAs(User::find($this->userId))->get(route('market-intelligence.reports.show', $report->id))
            ->assertOk()->assertSee('This report is being read.', false);

        $report->forceFill(['parse_status' => 'parsed'])->save();
        $this->actingAs(User::find($this->userId))->get(route('market-intelligence.reports.show', $report->id))
            ->assertOk()->assertDontSee('This report is being read.', false);
    }

    public function test_the_worker_run_has_room_for_the_ai_call_and_does_not_hold_a_worker_on_a_busy_lock(): void
    {
        $job = new ParseMarketReportJob(1);
        $this->assertGreaterThan(180 + AiReportReadService::LOCK_WAIT_SECONDS, $job->timeout, "longer than the AI's own 180s timeout");
        $this->assertLessThanOrEqual(10, AiReportReadService::LOCK_WAIT_SECONDS, 'the lock wait is short; a busy lock re-queues the report');
        $this->assertGreaterThan(now()->addMinutes(10)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_a_busy_ai_lock_puts_the_queued_report_back_instead_of_blocking(): void
    {
        $report = $this->upload();
        $lock = Cache::lock('market-report-ai-read', 60);
        $this->assertTrue($lock->get(), 'another report holds the platform-wide AI lock');

        $queueJob = \Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(30);

        $job = new ParseMarketReportJob($report->id, continuation: true);
        $job->setJob($queueJob);
        $started = microtime(true);
        app()->call([$job, 'handle']);
        $this->assertLessThan(20, microtime(true) - $started, 'waited only the short lock wait');

        Http::assertNothingSent();
        $report->refresh();
        $this->assertSame('parsing', $report->parse_status, 'still being read');
        $this->assertSame('parser', $report->read_method, 'nothing was decided or flagged');
        $this->assertNull($report->spot_check_results);
        $lock->release();
    }

    public function test_an_owner_rerun_from_the_queue_screen_also_runs_the_ai_read_on_the_queue(): void
    {
        config()->set('queue.default', 'database');
        $report = $this->upload();
        $report->forceFill(['read_method' => 'unread'])->save();

        $this->actingAs($this->owner)
            ->post(route('market-intelligence.reports.recogniser.rerun', $report->id), ['force_new_ai' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Re-run started'));

        Http::assertNothingSent();
        $this->assertSame('parsing', $report->refresh()->parse_status);
        $queued = $this->queuedParseJobs();
        $this->assertCount(1, $queued);
        $this->assertTrue($queued[0]->continuation);
        $this->assertTrue($queued[0]->adminRetry);
        $this->assertTrue($queued[0]->forceNewAiRead);
        $this->assertSame($this->owner->id, $queued[0]->actorUserId);
    }

    public function test_approving_a_layout_runs_on_the_queue_not_in_the_request(): void
    {
        $layout = MarketReportLearnedLayout::query()->create([
            'name' => 'L', 'publisher' => 'P', 'status' => 'learned', 'fingerprint' => 'x', 'anchors' => ['a', 'b', 'c'], 'mapping' => [], 'report_kind' => 'suburb',
        ]);
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('market-intelligence.reports.recogniser.layouts.approve', $layout->id))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'background'));

        Queue::assertPushed(\App\Jobs\MarketReports\ResolveLayoutCasesJob::class, fn ($j) => $j->layoutId === $layout->id && $j->byUserId === $this->owner->id);
        $this->assertSame('approved', $layout->refresh()->status);
    }

    public function test_a_queued_read_that_never_finishes_is_not_left_being_read_forever(): void
    {
        $report = $this->upload();
        $report->forceFill(['parse_status' => 'parsing', 'parse_started_at' => now()])->save();

        (new ParseMarketReportJob($report->id, continuation: true))->failed(new \RuntimeException('timed out'));

        $this->assertSame('failed', $report->refresh()->parse_status);
        $this->assertFalse($report->isBeingRead());
    }

    // ── 4. an agency over its limit holds only its own report ──

    public function test_an_agency_over_its_limit_does_not_block_other_agencies_in_the_same_layout(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '1');
        $this->assertSame('ai', $this->read($this->upload())->read_method, "Alpha's one read for the month");

        $held = $this->read($this->upload());
        $this->assertSame('unread', $held->read_method);
        $this->assertSame('ai_monthly_cap', $held->spot_check_results['reason_code']);
        $case = MarketReportLayoutCase::query()->sole();
        $this->assertSame(0, $case->ai_attempts, 'no AI attempt was made, so the case does not stop anyone else reading this layout');
        $this->assertSame($case->id, (int) $held->layout_case_id);
        Http::assertSentCount(1);

        // Bravo has its full allowance: its report in the SAME layout is read by the AI.
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $bravo = $this->read($this->upload(agencyId: $b, userId: $bu));
        $this->assertSame('ai', $bravo->read_method);
        $this->assertNull($bravo->layout_case_id);
        Http::assertSentCount(2);
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($b));

        // Alpha's report is still the only one waiting, in the one case.
        $this->assertSame(1, MarketReport::query()->withoutGlobalScopes()->where('layout_case_id', $case->id)->count());
        $this->assertSame('open', $case->refresh()->status);
    }

    public function test_a_real_ai_failure_still_blocks_a_second_attempt_on_the_same_layout(): void
    {
        $this->aiFails = true;
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $first  = $this->read($this->upload());
        $second = $this->read($this->upload(agencyId: $b, userId: $bu));

        Http::assertSentCount(1);
        $this->assertSame('ai_failed', $first->spot_check_results['reason_code']);
        $this->assertSame('layout_case_open', $second->spot_check_results['reason_code']);
        $this->assertSame(1, MarketReportLayoutCase::query()->sole()->ai_attempts);
    }

    public function test_the_platform_daily_cap_also_holds_without_blocking_the_layout_for_tomorrow(): void
    {
        DevSetting::set(ReportReadSettings::KEY_PLATFORM_DAILY_CAP, '0');
        $held = $this->read($this->upload());
        $this->assertSame('ai_platform_daily_cap', $held->spot_check_results['reason_code']);
        $this->assertSame(0, MarketReportLayoutCase::query()->sole()->ai_attempts);

        DevSetting::set(ReportReadSettings::KEY_PLATFORM_DAILY_CAP, '10');
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $this->assertSame('ai', $this->read($this->upload(agencyId: $b, userId: $bu))->read_method);
    }

    // ── 5. the team is alerted once per NEW layout case ──

    public function test_an_owner_rerun_of_a_report_already_in_a_case_does_not_alert_the_team_again(): void
    {
        $this->aiFails = true;
        $report = $this->upload();
        ParseMarketReportJob::dispatchSync($report->id);
        $teamAlerts = fn () => Notification::sent($this->owner, PillarEventNotification::class)->filter(fn ($n) => $n->eventKey === 'market_report.parse_unrecognized_team');
        $this->assertCount(1, $teamAlerts(), 'the new case alerted the team once');
        $this->assertSame(1, MarketReportLayoutCase::query()->count());

        // the owner re-runs it; the AI fails again; it is still the same case
        ParseMarketReportJob::dispatchSync($report->id, false, true, true);

        $this->assertCount(1, $teamAlerts(), 'no second "layout needs adding" alert for a case that already exists');
        $this->assertSame(1, MarketReportLayoutCase::query()->count());
    }

    public function test_a_report_that_cannot_be_fingerprinted_still_alerts_the_team_each_time(): void
    {
        $this->aiFails = true;
        ParseMarketReportJob::dispatchSync($this->upload('not_a_market_report.pdf')->id);
        ParseMarketReportJob::dispatchSync($this->upload('not_a_market_report.pdf')->id);

        $this->assertSame(0, MarketReportLayoutCase::query()->count(), 'no case exists for these');
        $alerts = Notification::sent($this->owner, PillarEventNotification::class)->filter(fn ($n) => $n->eventKey === 'market_report.parse_unrecognized_team');
        $this->assertCount(2, $alerts, 'nothing else covers them, so each one alerts');
    }

    // ── 6. a slot is used only when a request really went to the AI ──

    public function test_a_failure_before_anything_is_sent_gives_the_slots_back(): void
    {
        $report = $this->upload();
        file_put_contents(Storage::disk('local')->path($report->file_path), ''); // the stored PDF is empty: fails before the gateway

        $outcome = app(AiReportReadService::class)->read($report, Storage::disk('local')->path($report->file_path));

        Http::assertNothingSent();
        $this->assertFalse($outcome->wasRead());
        $this->assertSame('ai_failed', $outcome->code);
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($this->agencyId), "no read was made, so the agency's allowance is untouched");
        $this->assertSame(0, MarketReportAiUsage::usedToday());
        $case = MarketReportLayoutCase::query()->first();
        $this->assertSame(0, (int) ($case?->ai_attempts ?? 0), 'not counted as an AI attempt');
    }

    public function test_a_failure_after_the_request_went_out_keeps_the_slots(): void
    {
        $this->aiFails = true;
        $this->read($this->upload());

        Http::assertSentCount(1);
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertSame(1, MarketReportAiUsage::usedToday());
    }

    // ── 7. release returns the slot to the row it was taken from ──

    public function test_a_release_after_midnight_decrements_the_day_and_month_the_slot_was_taken_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:59:59', 'Africa/Johannesburg'));
        $monthKey = MarketReportAiUsage::monthKey();
        $dayKey   = MarketReportAiUsage::dayKey();
        $this->assertTrue(MarketReportAiUsage::reserveAgencyMonth($this->agencyId, 5, $monthKey));
        $this->assertTrue(MarketReportAiUsage::reservePlatformDay(10, $dayKey));
        // a slot already used in the NEXT period must not be the one released
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:05', 'Africa/Johannesburg'));
        $this->assertTrue(MarketReportAiUsage::reserveAgencyMonth($this->agencyId, 5));
        $this->assertTrue(MarketReportAiUsage::reservePlatformDay(10));

        MarketReportAiUsage::releaseBoth($this->agencyId, $monthKey, $dayKey);

        $count = fn (int $agency, string $period) => (int) MarketReportAiUsage::query()->where('agency_id', $agency)->where('period_key', $period)->value('reads_count');
        $this->assertSame(0, $count($this->agencyId, $monthKey), 'the month it was taken in is given back');
        $this->assertSame(0, $count(MarketReportAiUsage::PLATFORM, $dayKey), 'the day it was taken in is given back');
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId), "the new month's slot is untouched");
        $this->assertSame(1, MarketReportAiUsage::usedToday(), "today's slot is untouched");
    }

    // ── 8. agents see a plain message, never the provider's error text ──

    public function test_the_provider_error_text_never_reaches_the_report_or_the_team_email(): void
    {
        $this->aiFails = true;
        $report = $this->read($this->upload());

        $this->assertSame(AiReportReadService::FAILED_PLAIN, $report->ai_read_note);
        $this->assertStringNotContainsString('secret-provider-detail', json_encode($report->spot_check_results));
        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.parse_unrecognized_team'
            && str_contains($n->body, AiReportReadService::FAILED_PLAIN) && !str_contains($n->body, 'secret-provider-detail') && !str_contains($n->body, '529'));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<int, ParseMarketReportJob> the parse jobs waiting on the (database) queue */
    private function queuedParseJobs(): array
    {
        $out = [];
        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode($row->payload, true);
            $cmd = unserialize($payload['data']['command']);
            if ($cmd instanceof ParseMarketReportJob) {
                $out[] = $cmd;
            }
        }

        return $out;
    }

    /** Run the job (inline), then retire any layout it learned so the next report takes the AI path. */
    private function read(MarketReport $report): MarketReport
    {
        ParseMarketReportJob::dispatchSync($report->id);
        MarketReportLearnedLayout::query()->delete();

        return $report->refresh();
    }

    /** @return array{0:int,1:int} [agency id, user id] */
    private function agencyWithUser(string $name): array
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['agency_id' => $id, 'branch_id' => $id, 'role' => 'agent']);

        return [$id, (int) $user->id];
    }

    /** A copy of a fake suburb report; $unique appends bytes so every copy has its own file hash (no cache hit). */
    private function upload(string $fixture = 'fake_suburb_report_a.pdf', ?int $agencyId = null, ?int $userId = null, bool $unique = true): MarketReport
    {
        $agencyId ??= $this->agencyId;
        $userId   ??= $this->userId;
        $relative = "market-reports/test/{$agencyId}/" . Str::random(12) . '.pdf';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        copy(base_path('tests/Fixtures/market_reports/' . $fixture), $absolute);
        if ($unique) {
            file_put_contents($absolute, "\n%unique-" . Str::random(16) . "\n", FILE_APPEND);
        }
        $this->staged[] = $absolute;

        return MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => null,
            'file_path'           => $relative,
            'file_name'           => $unique ? Str::random(8) . '-' . $fixture : $fixture,
            'file_hash'           => hash_file('sha256', $absolute),
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PENDING,
            'spot_check_status'   => MarketReport::SPOT_PENDING,
            'data_points_count'   => 0,
        ]);
    }

    private function aiBody(): array
    {
        return $this->aiBodyFor($this->readA());
    }

    private function aiBodyFor(array $json): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage'   => ['input_tokens' => 9000, 'output_tokens' => 1800],
            'model'   => 'claude-sonnet-4-6',
        ];
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
}
