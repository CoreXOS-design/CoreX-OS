<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\DevSetting;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\MarketReports\MarketReportType;
use App\Models\PerformanceSetting;
use App\Models\Role;
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
 * Report recogniser — the CoreX admin side: the cross-agency queue, the learned layouts
 * screen, owner-only access, the agency switch, and the one-off reprocess command.
 * Spec: .ai/specs/mic-complete-spec.md §8.9.
 */
final class ReportRecogniserAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyA;
    private int $agencyB;
    private User $owner;
    private User $agent;
    /** @var string[] */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Bus::fake([SpotCheckMarketReportJob::class]);
        Notification::fake();

        // is_owner is not fillable — the real "System Owner" role, as the other owner-gate tests create it.
        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false, 'sort_order' => 1]);

        $this->agencyA = $this->agency('Alpha Realty');
        $this->agencyB = $this->agency('Bravo Homes');
        $this->owner = User::factory()->create(['agency_id' => $this->agencyA, 'branch_id' => $this->agencyA, 'role' => 'super_admin', 'name' => 'Owner One']);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyA, 'branch_id' => $this->agencyA, 'role' => 'agent', 'name' => 'Agent Anne']);

        foreach ([['other', GenericFallbackParser::class], ['learned_layout', LearnedLayoutParser::class]] as [$key, $class]) {
            MarketReportType::query()->firstOrCreate(['key' => $key], ['display_name' => $key, 'parser_class' => $class, 'expected_fields_json' => json_encode([]), 'auto_approve' => 0]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    // ── access ───────────────────────────────────────────────────────────

    public function test_only_owner_roles_can_open_the_queue_and_layouts(): void
    {
        $this->assertTrue($this->owner->isOwnerRole());
        $this->assertFalse($this->agent->isOwnerRole());

        $this->actingAs($this->agent)->get(route('market-intelligence.reports.recogniser.queue'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('market-intelligence.reports.recogniser.layouts'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('market-intelligence.reports.recogniser.settings'), [])->assertForbidden();

        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue'))->assertOk()->assertSee('Unrecognised &amp; AI-read reports', false);
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.layouts'))->assertOk()->assertSee('Learned layouts');
    }

    public function test_the_controller_itself_refuses_a_non_owner(): void
    {
        // the route's permission middleware is bypassed here on purpose: the controller's own gate must hold alone
        $req = \Illuminate\Http\Request::create('/x', 'GET');
        $req->setUserResolver(fn () => $this->agent);
        try {
            app(\App\Http\Controllers\CoreX\MarketReportRecogniserController::class)->queue($req);
            $this->fail('a non-owner must be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_the_parser_dashboard_links_to_the_queue_and_layouts(): void
    {
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.parser-dashboard'))
            ->assertOk()
            ->assertSee(route('market-intelligence.reports.recogniser.queue'), false)
            ->assertSee(route('market-intelligence.reports.recogniser.layouts'), false);
    }

    // ── queue: cross-agency list, search, sort, filter, pagination, empty state ──

    public function test_queue_lists_every_agency_and_supports_search_filter_and_sort(): void
    {
        $a = $this->report($this->agencyA, 'alpha-southport.pdf', 'ai', publisher: 'Coastline Property Data', confidence: 0.91, points: 18);
        $b = $this->report($this->agencyB, 'bravo-margate.pdf', 'unread', note: 'The AI read failed: boom', points: 0);
        $resolved = $this->report($this->agencyB, 'bravo-old.pdf', 'ai', publisher: 'Other Publisher', confidence: 0.6, resolved: true);
        $this->report($this->agencyA, 'normal-cma.pdf', 'parser'); // never in the queue
        $archived = $this->report($this->agencyA, 'archived-one.pdf', 'unread');
        $archived->delete();

        $q = fn (array $params = []) => $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', $params));

        $q()->assertOk()->assertSee('alpha-southport.pdf')->assertSee('bravo-margate.pdf')->assertSee('Alpha Realty')->assertSee('Bravo Homes')
            ->assertDontSee('bravo-old.pdf')->assertDontSee('normal-cma.pdf')->assertDontSee('archived-one.pdf');

        $q(['status' => 'all'])->assertSee('bravo-old.pdf');
        $q(['status' => 'resolved'])->assertSee('bravo-old.pdf')->assertDontSee('alpha-southport.pdf');
        $q(['how' => 'unread'])->assertSee('bravo-margate.pdf')->assertDontSee('alpha-southport.pdf');
        $q(['how' => 'ai'])->assertSee('alpha-southport.pdf')->assertDontSee('bravo-margate.pdf');
        $q(['agency' => $this->agencyB])->assertSee('bravo-margate.pdf')->assertDontSee('alpha-southport.pdf');
        $q(['publisher' => 'Coastline Property Data'])->assertSee('alpha-southport.pdf')->assertDontSee('bravo-margate.pdf');
        $q(['q' => 'margate'])->assertSee('bravo-margate.pdf')->assertDontSee('alpha-southport.pdf');
        $q(['q' => 'Alpha Realty'])->assertSee('alpha-southport.pdf')->assertDontSee('bravo-margate.pdf');
        $q(['q' => 'Coastline'])->assertSee('alpha-southport.pdf')->assertDontSee('bravo-margate.pdf');
        $q(['from' => now()->addDay()->toDateString()])->assertSee('No reports match these filters.');
        $q(['to' => now()->subDay()->toDateString()])->assertSee('No reports match these filters.');

        // sort: confidence ascending puts the null (unread) first or last but both rows are present; file name sort is deterministic
        $html = $q(['status' => 'all', 'sort' => 'file', 'dir' => 'asc'])->getContent();
        $this->assertLessThan(strpos($html, 'bravo-margate.pdf'), strpos($html, 'alpha-southport.pdf'));
        $html = $q(['status' => 'all', 'sort' => 'file', 'dir' => 'desc'])->getContent();
        $this->assertLessThan(strpos($html, 'alpha-southport.pdf'), strpos($html, 'bravo-margate.pdf'));
        $q(['sort' => 'not-a-column', 'dir' => 'sideways'])->assertOk(); // junk sort input is absorbed
    }

    public function test_queue_paginates_and_shows_an_empty_state(): void
    {
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue'))
            ->assertOk()->assertSee('Nothing to look at');

        for ($i = 1; $i <= 30; $i++) {
            $this->report($this->agencyA, sprintf('bulk-%02d.pdf', $i), 'unread');
        }
        $page1 = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['sort' => 'file', 'dir' => 'asc']));
        $page1->assertOk()->assertSee('bulk-01.pdf')->assertDontSee('bulk-30.pdf');
        $page2 = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['sort' => 'file', 'dir' => 'asc', 'page' => 2]));
        $page2->assertSee('bulk-30.pdf')->assertDontSee('bulk-01.pdf');
    }

    public function test_download_resolve_reopen_and_rerun(): void
    {
        $r = $this->report($this->agencyB, 'bravo-margate.pdf', 'unread', fixture: 'fake_suburb_report_a.pdf');

        $res = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.download', $r->id));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->actingAs($this->agent)->get(route('market-intelligence.reports.recogniser.download', $r->id))->assertForbidden();

        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.resolve', $r->id))->assertRedirect();
        $this->assertNotNull($r->refresh()->resolved_at);
        $this->assertSame($this->owner->id, (int) $r->resolved_by_user_id);
        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.reopen', $r->id))->assertRedirect();
        $this->assertNull($r->refresh()->resolved_at);

        // Re-run another agency's report from the queue: the whole pipeline runs, the AI reads it, nothing is hard-deleted
        Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->tinyRead())]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'model' => 'claude-sonnet-4-6'], 200)]);
        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.rerun', $r->id))->assertRedirect();
        $r->refresh();
        $this->assertSame('ai', $r->read_method);
        $this->assertSame($this->agencyB, (int) $r->agency_id);
        $this->assertDatabaseHas('market_report_comp_rows', ['market_report_id' => $r->id, 'agency_id' => $this->agencyB, 'sale_price' => 1500000]);
        Http::assertSentCount(1);

        // a second plain re-run reuses the cached answer; "new AI read" pays again
        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.rerun', $r->id))->assertRedirect();
        Http::assertSentCount(1);
        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.rerun', $r->id), ['force_new_ai' => '1'])->assertRedirect();
        Http::assertSentCount(2);
        $this->assertSoftDeletedCount($r->id);
    }

    // ── learned layouts: approve, rename, retire, restore; settings ──────

    public function test_layout_approve_rename_retire_restore_and_filters(): void
    {
        $l = MarketReportLearnedLayout::create([
            'name' => 'Coastline Property Data — Suburb Sales Report', 'publisher' => 'Coastline Property Data', 'status' => 'learned',
            'fingerprint' => hash('sha256', 'x'), 'anchors' => ['a', 'b', 'c'], 'mapping' => ['version' => 1, 'tables' => [], 'scalars' => []], 'report_kind' => 'comps', 'selftest_score' => 1.0,
        ]);
        $other = MarketReportLearnedLayout::create([
            'name' => 'Zulu Reports — Layout', 'publisher' => 'Zulu Reports', 'status' => 'learned',
            'fingerprint' => hash('sha256', 'y'), 'anchors' => ['a', 'b', 'c'], 'mapping' => ['version' => 1, 'tables' => [], 'scalars' => []],
        ]);
        $go = fn (string $route, int $id, array $data = []) => $this->actingAs($this->owner)->post(route("market-intelligence.reports.recogniser.layouts.$route", $id), $data)->assertRedirect();

        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.layouts'))->assertSee('Zulu Reports')->assertSee('Coastline Property Data');
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.layouts', ['q' => 'zulu']))->assertSee('Zulu Reports')->assertDontSee('Suburb Sales Report');

        $go('approve', $l->id);
        $this->assertSame('approved', $l->refresh()->status);
        $this->assertSame($this->owner->id, (int) $l->approved_by_user_id);

        $go('rename', $l->id, ['name' => 'Coastline — suburb report']);
        $this->assertSame('Coastline — suburb report', $l->refresh()->name);
        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.layouts.rename', $l->id), ['name' => ''])->assertSessionHasErrors('name');

        $go('retire', $l->id);
        $this->assertSoftDeleted('market_report_learned_layouts', ['id' => $l->id]);
        $this->assertSame(1, MarketReportLearnedLayout::query()->count(), 'a retired layout is no longer usable');
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.layouts', ['status' => 'retired']))->assertSee('Coastline — suburb report')->assertDontSee('Zulu Reports');
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.layouts', ['status' => 'approved']))->assertDontSee('Coastline — suburb report');

        $go('restore', $l->id);
        $this->assertNull($l->refresh()->deleted_at);
        $this->actingAs($this->agent)->post(route('market-intelligence.reports.recogniser.layouts.retire', $other->id))->assertForbidden();
    }

    public function test_system_settings_save_and_validate(): void
    {
        $post = fn (array $d) => $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.settings'), $d);
        $good = ['report_ai_max_pages' => 25, 'report_ai_min_confidence' => 0.65, 'report_layout_min_match' => 0.8, 'report_layout_selftest_min' => 0.95, 'report_ai_monthly_cap' => 7, 'report_ai_platform_daily_cap' => 12];

        $post($good)->assertRedirect()->assertSessionHasNoErrors();
        $s = app(ReportReadSettings::class);
        $this->assertSame(25, $s->maxPages());
        $this->assertEqualsWithDelta(0.65, $s->minConfidence(), 0.0001);
        $this->assertEqualsWithDelta(0.8, $s->layoutMinMatch(), 0.0001);
        $this->assertEqualsWithDelta(0.95, $s->selftestMin(), 0.0001);
        $this->assertSame(7, $s->monthlyCap());
        $this->assertSame(12, $s->platformDailyCap());

        $post(array_merge($good, ['report_ai_monthly_cap' => -1]))->assertSessionHasErrors('report_ai_monthly_cap');
        $post(array_merge($good, ['report_ai_platform_daily_cap' => 501]))->assertSessionHasErrors('report_ai_platform_daily_cap');
        $post(array_merge($good, ['report_ai_monthly_cap' => 0]))->assertSessionHasNoErrors();
        $this->assertSame(0, $s->monthlyCap(), '0 is allowed: no AI reads at all');
        $post($good);

        $post(array_merge($good, ['report_ai_min_confidence' => 1.5]))->assertSessionHasErrors('report_ai_min_confidence');
        $post(array_merge($good, ['report_ai_max_pages' => 0]))->assertSessionHasErrors('report_ai_max_pages');
        $this->assertSame(25, $s->maxPages(), 'a rejected save changes nothing');
    }

    public function test_defaults_apply_when_nothing_is_set(): void
    {
        DevSetting::query()->whereIn('key', [ReportReadSettings::KEY_MAX_PAGES, ReportReadSettings::KEY_MIN_CONFIDENCE, ReportReadSettings::KEY_LAYOUT_MATCH, ReportReadSettings::KEY_SELFTEST_MIN, ReportReadSettings::KEY_MONTHLY_CAP, ReportReadSettings::KEY_PLATFORM_DAILY_CAP])->delete();
        $s = app(ReportReadSettings::class);
        $this->assertSame(5, $s->monthlyCap(), "Johan's ruling: 5 AI reads per agency per month by default");
        $this->assertSame(10, $s->platformDailyCap(), "Johan's ruling: 10 AI reads per day across all agencies by default");
        $this->assertSame(40, $s->maxPages());
        $this->assertEqualsWithDelta(0.5, $s->minConfidence(), 0.0001);
        $this->assertEqualsWithDelta(0.85, $s->layoutMinMatch(), 0.0001);
        $this->assertEqualsWithDelta(0.9, $s->selftestMin(), 0.0001);
    }

    // ── agency switch: settings page + Setup Wizard ──────────────────────

    public function test_agency_switch_is_saved_from_the_settings_page_and_guarded_against_absence(): void
    {
        $base = ['claim_warn_days' => 7, 'claim_release_days' => 10, 'mic_counts_cache_fresh_seconds' => 60, 'mic_counts_cache_stale_seconds' => 300];
        $put = fn (array $d) => $this->actingAs($this->owner)->put(route('settings.prospecting.stale-rules.update'), array_merge($base, $d));

        $put(['report_ai_read_enabled' => '0'])->assertRedirect();
        $this->assertFalse(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
        $put([])->assertRedirect(); // a form that did not carry the switch must NOT turn it back on (spec §6.1)
        $this->assertFalse(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
        $put(['report_ai_read_enabled' => '1'])->assertRedirect();
        $this->assertTrue(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
        $this->assertTrue(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyB), 'the switch is per agency');
        $this->actingAs($this->owner)->get(route('settings.prospecting.stale-rules.edit'))->assertOk()->assertSee('Read unrecognised reports with AI');
    }

    public function test_setup_wizard_carries_the_switch_and_its_saver_is_guarded(): void
    {
        $step = config('agency-onboarding-copy.market_intelligence');
        $keys = array_column($step['controls'], 'key');
        $this->assertContains('report_ai_read_enabled', $keys);
        $control = collect($step['controls'])->firstWhere('key', 'report_ai_read_enabled');
        $this->assertSame('toggle', $control['type']);
        $this->assertSame('perf', $control['source']);
        $this->assertNotEmpty($control['explain']);
        $this->assertNotEmpty($control['affects']);
        $this->assertContains('updateReportAiRead', array_column($step['savers'], 'method'));

        // the saver the wizard calls: absent leaves the value alone, a posted 0/1 sets it
        $call = function (array $data) {
            $this->actingAs($this->owner);
            $req = \Illuminate\Http\Request::create('/x', 'POST', $data);
            $req->setUserResolver(fn () => $this->owner);
            app()->call([app(\App\Http\Controllers\Settings\Prospecting\StaleRulesController::class), 'updateReportAiRead'], ['request' => $req]);
        };
        PerformanceSetting::set(ReportReadSettings::KEY_AI_ENABLED, 0, $this->agencyA);
        $call([]);
        $this->assertFalse(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
        $call(['report_ai_read_enabled' => '1']);
        $this->assertTrue(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
        $call(['report_ai_read_enabled' => '0']);
        $this->assertFalse(app(ReportReadSettings::class)->aiEnabledForAgency($this->agencyA));
    }

    // ── the one-off command ───────────────────────────────────────────────

    public function test_reprocess_command_dry_run_counts_and_changes_nothing_then_apply_never_notifies(): void
    {
        $r1 = $this->report($this->agencyA, 'old-one.pdf', 'unread', fixture: 'fake_suburb_report_a.pdf');
        $r2 = $this->report($this->agencyB, 'old-two.pdf', 'unread', fixture: 'fake_suburb_report_b.pdf');
        $legacy = $this->report($this->agencyA, 'legacy-flagged.pdf', 'parser', fixture: 'fake_suburb_report_a.pdf');
        $legacy->forceFill(['spot_check_status' => 'flagged', 'spot_check_results' => ['flagged_by' => 'unrecognized_document']])->save();
        $this->report($this->agencyA, 'fine.pdf', 'parser', fixture: 'fake_suburb_report_a.pdf');
        Http::fake(['https://api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->tinyRead())]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10], 'model' => 'claude-sonnet-4-6'], 200)]);

        $this->artisan('market-reports:reprocess-unread')
            ->expectsOutputToContain('DRY RUN — 3 report(s)')
            ->expectsOutputToContain('Dry run — nothing was changed')
            ->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('unread', $r1->refresh()->read_method);
        $this->assertSame('parser', $legacy->refresh()->read_method);

        $this->artisan('market-reports:reprocess-unread', ['--agency' => $this->agencyB])->expectsOutputToContain('DRY RUN — 1 report(s)')->assertSuccessful();

        $this->artisan('market-reports:reprocess-unread', ['--apply' => true])->expectsOutputToContain('APPLY — 3 report(s)')->assertSuccessful();
        $this->assertSame('ai', $r1->refresh()->read_method);
        $this->assertSame('ai', $r2->refresh()->read_method);
        Notification::assertNothingSent(); // a bulk re-run never re-tells uploaders about old reports
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function agency(string $name): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(5), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function report(int $agencyId, string $name, string $method, ?string $publisher = null, ?float $confidence = null, ?string $note = null,
        bool $resolved = false, int $points = 0, ?string $fixture = null): MarketReport
    {
        $relative = "market-reports/test/{$agencyId}/" . Str::random(12) . '.pdf';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        copy(base_path('tests/Fixtures/market_reports/' . ($fixture ?? 'not_a_market_report.pdf')), $absolute);
        $this->staged[] = $absolute;

        return MarketReport::create([
            'agency_id' => $agencyId, 'uploaded_by_user_id' => $this->agent->id, 'file_path' => $relative, 'file_name' => $name,
            'file_hash' => hash('sha256', $name . Str::random(8)), 'report_date' => now()->toDateString(),
            'parse_status' => 'parsed', 'spot_check_status' => 'pending', 'data_points_count' => $points,
            'read_method' => $method, 'read_publisher' => $publisher, 'ai_read_confidence' => $confidence, 'ai_read_note' => $note,
            'resolved_at' => $resolved ? now() : null,
        ]);
    }

    private function assertSoftDeletedCount(int $reportId): void
    {
        // earlier runs' rows were superseded (soft-deleted), never hard-deleted
        $this->assertGreaterThan(0, DB::table('market_report_comp_rows')->where('market_report_id', $reportId)->whereNotNull('deleted_at')->count());
    }

    private function tinyRead(): array
    {
        return [
            'publisher' => 'Coastline Property Data', 'report_title' => 'Suburb Sales Report', 'suburb' => 'Southport',
            'comps' => [['kind' => 'comp', 'address' => '1 Test Road', 'sale_date' => '2026-05-01', 'sale_price' => 1500000, 'extent_m2' => 600]],
            'suburb_stats' => [], 'valuation' => [], 'layout' => [], 'confidence' => 0.9,
        ];
    }
}
