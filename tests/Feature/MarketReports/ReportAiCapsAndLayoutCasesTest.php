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
use App\Services\MarketReports\LayoutCaseResolver;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use App\Services\MarketReports\ReportReadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report recogniser — AI cost protection (Johan's ruling, 20 Oct 2026;
 * .ai/specs/mic-complete-spec.md §8.9 "AI cost protection: caps and layout cases"):
 * a monthly AI cap per agency, a daily cap across the platform, one AI attempt per unknown
 * layout platform-wide, the CoreX-team alerts, and what an approval does.
 * The AI is faked at the HTTP edge, so the real gateway, AiSpendGuard and recorder all run.
 */
final class ReportAiCapsAndLayoutCasesTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_RECOGNISED = 'Your report could not be recognised. It has been sent to CoreX to fix. CoreX will report back to you as soon as possible.';

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
        // One stub for the whole test, steered by $aiFails / $aiJson (a second Http::fake would be shadowed by this one).
        Http::fake(['https://api.anthropic.com/*' => fn () => $this->aiFails
            ? Http::response(['error' => 'overloaded'], 529)
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

    // ── 4a. 50 agencies, one new layout: ONE AI attempt in total ──

    public function test_fifty_agencies_uploading_the_same_new_layout_cost_one_ai_call(): void
    {
        $this->aiFails = true;

        $reports = [];
        for ($i = 1; $i <= 50; $i++) {
            [$agencyId, $userId] = $i === 1 ? [$this->agencyId, $this->userId] : $this->agencyWithUser("Agency {$i}");
            $report = $this->upload('fake_suburb_report_a.pdf', $agencyId, $userId);
            ParseMarketReportJob::dispatchSync($report->id);
            $reports[] = $report->refresh();
        }

        Http::assertSentCount(1); // the whole point: one attempt platform-wide

        $case = MarketReportLayoutCase::query()->sole();
        $this->assertSame('open', $case->status);
        $this->assertSame(1, $case->ai_attempts);
        $this->assertSame($reports[0]->id, (int) $case->first_report_id);

        foreach ($reports as $i => $r) {
            $this->assertSame('unread', $r->read_method, "report {$i}");
            $this->assertSame($case->id, (int) $r->layout_case_id, "report {$i}");
            $this->assertTrue(is_file(Storage::disk('local')->path($r->file_path)), 'the file stays saved');
            Notification::assertSentTo(User::find($r->uploaded_by_user_id), PillarEventNotification::class, fn ($n) => $n->body === self::NOT_RECOGNISED);
        }
        $this->assertSame('ai_failed', $reports[0]->spot_check_results['reason_code']);
        $this->assertSame('layout_case_open', $reports[1]->spot_check_results['reason_code']);
        $this->assertSame('layout_case_open', $reports[49]->spot_check_results['reason_code']);

        // only the first agency used (and wasted) one of its reads; the other 49 are untouched
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertSame(1, MarketReportAiUsage::usedToday());

        // the CoreX team heard about this layout once — with the file — not 50 times
        $teamAlerts = Notification::sent($this->owner, PillarEventNotification::class)
            ->filter(fn ($n) => $n->eventKey === 'market_report.parse_unrecognized_team');
        $this->assertCount(1, $teamAlerts);
        $this->assertStringContainsString('fake_suburb_report_a.pdf', $teamAlerts->first()->body);
        $this->assertStringContainsString('Alpha Realty', $teamAlerts->first()->body);
        $this->assertSame(route('market-intelligence.reports.recogniser.queue', [], false), $teamAlerts->first()->actionUrl);
    }

    public function test_a_layout_the_ai_was_unsure_about_also_blocks_further_attempts(): void
    {
        $unsure = $this->readA();
        $unsure['confidence'] = 0.2;
        $this->aiJson = $unsure;

        $first = $this->upload();
        ParseMarketReportJob::dispatchSync($first->id);
        [$otherAgency, $otherUser] = $this->agencyWithUser('Bravo Homes');
        $second = $this->upload('fake_suburb_report_b.pdf', $otherAgency, $otherUser);
        ParseMarketReportJob::dispatchSync($second->id);

        Http::assertSentCount(1);
        $this->assertSame('ai_unusable', $first->refresh()->spot_check_results['reason_code']);
        $this->assertSame('layout_case_open', $second->refresh()->spot_check_results['reason_code']);
        $this->assertSame(1, MarketReportLayoutCase::query()->sole()->ai_attempts);
    }

    // ── 1. the monthly cap, per agency ──

    public function test_the_agency_monthly_cap_queues_the_next_new_layout_without_an_ai_call(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '2');
        $this->read($this->upload());
        $this->read($this->upload());
        Http::assertSentCount(2);
        $this->assertSame(2, MarketReportAiUsage::usedThisMonth($this->agencyId));
        Notification::fake();

        $held = $this->upload();
        ParseMarketReportJob::dispatchSync($held->id);
        $held->refresh();

        Http::assertSentCount(2); // no third call
        $this->assertSame('unread', $held->read_method);
        $this->assertSame('ai_monthly_cap', $held->spot_check_results['reason_code']);
        $this->assertSame('Held – agency monthly AI limit', $held->heldLabel());
        $this->assertSame('flagged', $held->spot_check_status);
        $this->assertSame(2, MarketReportAiUsage::usedThisMonth($this->agencyId));
        Notification::assertSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n) => $n->body === self::NOT_RECOGNISED
            && $n->actionUrl === route('market-intelligence.reports.show', $held->id, false));
        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.parse_unrecognized_team'
            && str_contains($n->body, 'Alpha Realty') && str_contains($n->body, $held->file_name));
        Notification::assertNotSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n) => str_ends_with($n->eventKey, '_team'));

        // the next report in that same layout queues under the case: no AI, no second team alert
        Notification::fake();
        $again = $this->upload();
        ParseMarketReportJob::dispatchSync($again->id);
        Http::assertSentCount(2);
        $this->assertSame('layout_case_open', $again->refresh()->spot_check_results['reason_code']);
        $this->assertSame($held->refresh()->layout_case_id, $again->layout_case_id);
        Notification::assertNotSentTo($this->owner, PillarEventNotification::class);
        Notification::assertSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n) => $n->body === self::NOT_RECOGNISED);
    }

    public function test_cached_answers_and_learned_layouts_never_count_and_are_never_blocked(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '1');

        $first = $this->upload('fake_suburb_report_a.pdf', unique: false);
        ParseMarketReportJob::dispatchSync($first->id);
        $this->assertSame('ai', $first->refresh()->read_method);
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId));

        // cap reached, but a second report in the learned layout is still read — free
        $learned = $this->upload('fake_suburb_report_b.pdf', unique: false);
        ParseMarketReportJob::dispatchSync($learned->id);
        $this->assertSame('learned', $learned->refresh()->read_method);

        // …and the very same file again, from another agency (layout retired so it takes the AI path), is a cache hit — free
        MarketReportLearnedLayout::query()->delete();
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $again = $this->upload('fake_suburb_report_a.pdf', $b, $bu, unique: false);
        ParseMarketReportJob::dispatchSync($again->id);
        $this->assertSame('ai', $again->refresh()->read_method);

        Http::assertSentCount(1);
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId), 'neither counted');
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($b));
    }

    public function test_another_agency_has_its_own_allowance_and_a_new_month_starts_again(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '1');
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Africa/Johannesburg'));

        $this->read($this->upload());
        $this->assertSame('ai_monthly_cap', $this->read($this->upload())->spot_check_results['reason_code'], 'agency 1 is at its cap');

        [$beta, $betaUser] = $this->agencyWithUser('Bravo Homes');
        // (the layout case opened for agency 1 would queue this one — a different layout shows agency 2's own allowance)
        MarketReportLayoutCase::query()->update(['status' => 'resolved']);
        $this->assertSame('ai', $this->read($this->upload(agencyId: $beta, userId: $betaUser))->read_method);

        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'Africa/Johannesburg'));
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertSame('ai', $this->read($this->upload())->read_method, 'a new month, a new allowance');
    }

    public function test_cap_zero_means_no_ai_reads_at_all(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '0');
        $report = $this->read($this->upload());

        Http::assertNothingSent();
        $this->assertSame('ai_monthly_cap', $report->spot_check_results['reason_code']);
    }

    // ── 4c. the platform-wide daily cap ──

    public function test_the_platform_daily_cap_queues_everything_and_alerts_the_team_once(): void
    {
        DevSetting::set(ReportReadSettings::KEY_PLATFORM_DAILY_CAP, '2');
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        [$c, $cu] = $this->agencyWithUser('Charlie Realty');
        $this->read($this->upload());
        $this->read($this->upload(agencyId: $b, userId: $bu));
        Http::assertSentCount(2);
        Notification::fake();

        $x = $this->upload(agencyId: $c, userId: $cu);
        ParseMarketReportJob::dispatchSync($x->id);
        // a second report, a different document (no layout to fingerprint), same day
        $y = $this->upload('not_a_market_report.pdf', $c, $cu);
        ParseMarketReportJob::dispatchSync($y->id);

        Http::assertSentCount(2);
        $this->assertSame('ai_platform_daily_cap', $x->refresh()->spot_check_results['reason_code']);
        $this->assertSame('ai_platform_daily_cap', $y->refresh()->spot_check_results['reason_code']);
        $this->assertSame('Held – platform daily AI limit', $x->heldLabel());
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($c), "a read that was never made does not use the agency's allowance");
        $this->assertSame(2, MarketReportAiUsage::usedToday());

        // both agents are told; the team gets ONE "AI read cap hit" alert, not two
        Notification::assertSentTo(User::find($cu), PillarEventNotification::class, fn ($n) => $n->body === self::NOT_RECOGNISED);
        $hits = Notification::sent($this->owner, PillarEventNotification::class)->filter(fn ($n) => $n->eventKey === 'market_report.ai_cap_hit_team');
        $this->assertCount(1, $hits);
        $this->assertSame('AI read cap hit', $hits->first()->title);
        $this->assertSame(route('market-intelligence.reports.recogniser.queue', [], false), $hits->first()->actionUrl);

        // tomorrow it works again
        Carbon::setTestNow(now()->addDay());
        $this->assertSame(0, MarketReportAiUsage::usedToday());
    }

    // ── 3. approval: counters reset, queued reports re-run on the layout (no AI), agents told ──

    public function test_adding_the_layout_from_the_queue_resets_counters_reruns_queued_reports_and_tells_agents(): void
    {
        // the AI is down: agency 1's report fails, agencies 2 and 3 queue under the same case
        $this->aiFails = true;
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        [$c, $cu] = $this->agencyWithUser('Charlie Realty');
        $r1 = $this->upload('fake_suburb_report_a.pdf', $this->agencyId, $this->userId);
        $r2 = $this->upload('fake_suburb_report_b.pdf', $b, $bu);
        $r3 = $this->upload('fake_suburb_report_b.pdf', $c, $cu);
        foreach ([$r1, $r2, $r3] as $r) {
            ParseMarketReportJob::dispatchSync($r->id);
        }
        Http::assertSentCount(1);
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertSame(['unread', 'unread', 'unread'], [$r1->refresh()->read_method, $r2->refresh()->read_method, $r3->refresh()->read_method]);
        Notification::fake();

        // the AI is back; the CoreX team re-runs the first report from the queue (it learns the layout)
        $this->aiFails = false;
        $this->actingAs($this->owner)
            ->post(route('market-intelligence.reports.recogniser.rerun', $r1->id), ['force_new_ai' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 queued layout case(s) closed') && str_contains($s, '2 of 2 queued report(s) now imported'));

        Http::assertSentCount(2); // the failed one + the team's own retry — the queued reports cost nothing
        $this->assertSame('ai', $r1->refresh()->read_method);
        foreach ([$r2, $r3] as $r) {
            $r->refresh();
            $this->assertSame('learned', $r->read_method, 'read on the learned layout');
            $this->assertGreaterThan(0, (int) $r->data_points_count);
            $this->assertSame(4, \App\Models\MarketReports\MarketReportCompRow::withoutGlobalScopes()->where('market_report_id', $r->id)->where('row_type', 'comp')->count());
        }

        // counters reset for the agencies in the case
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertNotNull(MarketReportAiUsage::query()->where('agency_id', $this->agencyId)->value('last_reset_at'));

        // the agents of the re-run reports are told, in the agreed words — and the agency that was read by AI is not
        foreach ([$bu, $cu] as $uid) {
            Notification::assertSentTo(User::find($uid), PillarEventNotification::class, fn ($n) => $n->body === 'Your report is now recognised and has been imported.'
                && str_contains($n->actionUrl ?? '', '/reports/'));
        }
        // no "could not be recognised" noise during the automatic re-run
        Notification::assertNotSentTo(User::find($bu), PillarEventNotification::class, fn ($n) => $n->body === self::NOT_RECOGNISED);

        $case = MarketReportLayoutCase::query()->sole();
        $this->assertSame('resolved', $case->status);
        $this->assertNotNull($case->resolved_at);
        $this->assertSame(MarketReportLearnedLayout::query()->value('id'), (int) $case->learned_layout_id);
    }

    public function test_approving_a_layout_does_the_same_without_any_ai_call(): void
    {
        $this->aiFails = true;
        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $r1 = $this->upload('fake_suburb_report_a.pdf', $this->agencyId, $this->userId);
        $r2 = $this->upload('fake_suburb_report_b.pdf', $b, $bu);
        ParseMarketReportJob::dispatchSync($r1->id);
        ParseMarketReportJob::dispatchSync($r2->id);
        $this->assertSame('layout_case_open', $r2->refresh()->spot_check_results['reason_code']);

        // the team's retry learns the layout (job-level, so only the approval does the resolving)
        $this->aiFails = false;
        ParseMarketReportJob::dispatchSync($r1->id, true, true, true);
        $layout = MarketReportLearnedLayout::query()->sole();
        $this->assertSame('learned', $layout->status);
        $this->assertSame('learned', MarketReportLayoutCase::query()->sole()->status);
        $this->assertSame('unread', $r2->refresh()->read_method, 'still queued until the team approves');
        $this->assertSame(2, MarketReportAiUsage::usedThisMonth($this->agencyId));
        Notification::fake();
        $callsBefore = count(Http::recorded());

        $this->actingAs($this->owner)
            ->post(route('market-intelligence.reports.recogniser.layouts.approve', $layout->id))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Layout approved.') && str_contains($s, '1 of 1 queued report(s) now imported'));

        $this->assertCount($callsBefore, Http::recorded(), 'approval and the automatic re-run make no AI call');
        $this->assertSame('approved', $layout->refresh()->status);
        $this->assertSame('learned', $r2->refresh()->read_method);
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($this->agencyId));
        Notification::assertSentTo(User::find($bu), PillarEventNotification::class, fn ($n) => $n->body === LayoutCaseResolver::RECOGNISED);
        $this->assertSame('resolved', MarketReportLayoutCase::query()->sole()->status);
    }

    // ── queue screen ──

    public function test_the_queue_can_show_only_held_reports_and_names_the_layout_case(): void
    {
        DevSetting::set(ReportReadSettings::KEY_MONTHLY_CAP, '0');
        $held = $this->upload();
        ParseMarketReportJob::dispatchSync($held->id);
        $failed = $this->upload('not_a_market_report.pdf');
        $failed->forceFill(['read_method' => 'unread', 'spot_check_results' => ['flagged_by' => 'unrecognized_document', 'reason_code' => 'ai_failed']])->save();

        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['how' => 'held']))
            ->assertOk()
            ->assertSee($held->file_name)
            ->assertSee('Held – agency monthly AI limit')
            ->assertSee('Layout case #' . $held->refresh()->layout_case_id)
            ->assertDontSee('>' . $failed->file_name . '<', false);

        // the agent's own page says it in plain words and hides the technical note
        $this->actingAs(User::find($this->userId))->get(route('market-intelligence.reports.show', $held->id))->assertOk()
            ->assertSee('Your report could not be recognised. It has been sent to CoreX to fix.', false)
            ->assertDontSee('monthly limit', false);
    }

    // ── counters ──

    public function test_slots_are_taken_atomically_and_a_refused_call_gives_them_back(): void
    {
        $this->assertTrue(MarketReportAiUsage::reserveAgencyMonth($this->agencyId, 2));
        $this->assertTrue(MarketReportAiUsage::reserveAgencyMonth($this->agencyId, 2));
        $this->assertFalse(MarketReportAiUsage::reserveAgencyMonth($this->agencyId, 2));
        $this->assertSame(2, MarketReportAiUsage::usedThisMonth($this->agencyId));

        $this->assertTrue(MarketReportAiUsage::reservePlatformDay(1));
        MarketReportAiUsage::releaseBoth($this->agencyId); // the call was refused before it was sent
        $this->assertSame(1, MarketReportAiUsage::usedThisMonth($this->agencyId));
        $this->assertSame(0, MarketReportAiUsage::usedToday());
        MarketReportAiUsage::releaseBoth($this->agencyId);
        MarketReportAiUsage::releaseBoth($this->agencyId);
        $this->assertSame(0, MarketReportAiUsage::usedThisMonth($this->agencyId), 'never below zero');

        $this->assertTrue(MarketReportAiUsage::claimPlatformCapAlert());
        $this->assertFalse(MarketReportAiUsage::claimPlatformCapAlert(), 'the alert is claimed once per day');
    }

    // ── every AI read still warns the CoreX team; the agent gets their own words ──

    public function test_every_ai_read_alerts_the_team_with_agency_file_publisher_and_queue_link(): void
    {
        $report = $this->read($this->upload('fake_suburb_report_a.pdf', unique: false));
        $this->assertSame('ai', $report->read_method);

        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n)
            => $n->eventKey === 'market_report.read_by_ai_team'
            && str_contains($n->body, 'Agency: Alpha Realty.')
            && str_contains($n->body, 'File: fake_suburb_report_a.pdf.')
            && str_contains($n->body, 'Publisher: Coastline Property Data.')
            && str_contains($n->body, 'new AI read')
            && $n->actionUrl === route('market-intelligence.reports.recogniser.queue', [], false)
            && in_array('mail', $n->channels, true) && in_array('database', $n->channels, true) && $n->mailer === 'corex');
        Notification::assertSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n)
            => $n->body === "We couldn't read this layout with a known format, so CoreX read it with AI. Please check the figures.");
        Notification::assertNotSentTo(User::find($this->userId), PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.read_by_ai_team');
        Notification::assertNotSentTo($this->owner, PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.read_by_ai');
    }

    public function test_a_reused_answer_is_still_reported_to_the_team_but_says_no_new_cost(): void
    {
        $this->read($this->upload('fake_suburb_report_a.pdf', unique: false));
        MarketReportLearnedLayout::query()->delete();
        Notification::fake();

        [$b, $bu] = $this->agencyWithUser('Bravo Homes');
        $this->read($this->upload('fake_suburb_report_a.pdf', $b, $bu, unique: false));

        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n)
            => $n->eventKey === 'market_report.read_by_ai_team' && str_contains($n->body, 'no new AI cost'));
    }

    public function test_an_uploader_who_is_on_the_team_gets_the_team_version_only(): void
    {
        $report = $this->upload('fake_suburb_report_a.pdf', unique: false);
        $report->forceFill(['uploaded_by_user_id' => $this->owner->id])->save();
        ParseMarketReportJob::dispatchSync($report->id);

        Notification::assertSentToTimes($this->owner, PillarEventNotification::class, 1);
        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n) => $n->eventKey === 'market_report.read_by_ai_team');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Run the job, then retire any layout it learned: a retired layout is never used or
     * re-learned, so every report in these tests takes the AI path.
     */
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
