<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\Admin\CompanyPerformanceService;
use App\Services\Agent\AgentPerformanceService;
use App\Services\Agent\MyPortalNumbers;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 3 (P4): the daily-activity points rule is written once
 * (ActivityPointsService) and every screen that totals points reads it.
 *
 * Input paths proven: every state x source combination (only confirmed/overridden from manual and the two
 * auto sources count); an activity switched off or hidden still counts (earned credit stays earned); a
 * fractional weight; another agency's entries never counted; the SAME agent and month giving the SAME
 * total on the agent daily page, My Portal, the agent dashboard snapshot, the three summaries and the
 * company rollup; a summary listing credit on a switched-off activity and its drill-down opening; a
 * foreign or unknown activity id answering 404; the strips that used to read the abandoned
 * `daily_activities` table reading the live entries; the data move (calendar mappings and provisional
 * calendar entries re-based, confirmed credit untouched, second run a no-op, an agency-chosen count left
 * alone); the capture entries gone from the Agency Tracker panel.
 */
final class DailyPointsSharedRuleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = $this->user('agent');
        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────

    private function user(string $role, ?Agency $agency = null, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'agency_id' => ($agency ?? $this->agency)->id, 'branch_id' => ($branch ?? $this->branch)->id,
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function definition(float $weight, string $name = null, bool $enabled = true, string $scope = 'system'): int
    {
        return (int) DB::table('activity_definitions')->insertGetId([
            'name' => $name ?? 'Owner call ' . Str::random(5), 'weight' => $weight, 'sort_order' => 1, 'scope' => $scope,
            'is_enabled' => $enabled ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(User $u, string $date, int $value, int $defId, string $state = 'confirmed', string $source = 'manual'): void
    {
        DB::table('daily_activity_entries')->insert([
            'activity_date' => $date, 'period' => substr($date, 0, 7), 'user_id' => $u->id, 'agency_id' => $u->agency_id,
            'branch_id' => $u->branch_id, 'activity_definition_id' => $defId, 'value' => $value,
            'point_state' => $state, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The one scenario every "same number everywhere" test reads: 30 from an offered activity + 100 from one switched off = 130. */
    private function scenario(): array
    {
        $offered = $this->definition(10, 'Call an owner');
        $off = $this->definition(50, '[Auto] mandate.signed', false);
        $this->entry($this->agent, '2026-10-05', 3, $offered);
        $this->entry($this->agent, '2026-10-20', 2, $off, 'confirmed', 'auto_instant');

        return [$offered, $off];
    }

    // ── what counts ──────────────────────────────────────────────────────────────────────

    public function test_only_confirmed_or_overridden_from_scored_sources_count(): void
    {
        $def = $this->definition(10);
        $this->entry($this->agent, '2026-10-01', 1, $def, 'confirmed', 'manual');
        $this->entry($this->agent, '2026-10-02', 1, $def, 'overridden', 'manual');
        $this->entry($this->agent, '2026-10-03', 1, $def, 'confirmed', 'auto_calendar');
        $this->entry($this->agent, '2026-10-04', 1, $def, 'confirmed', 'auto_instant');
        $this->entry($this->agent, '2026-10-05', 5, $def, 'provisional', 'auto_calendar');
        $this->entry($this->agent, '2026-10-06', 5, $def, 'revoked', 'auto_instant');
        $this->entry($this->agent, '2026-10-07', 5, $def, 'confirmed', 'auto_other');

        $total = ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id));

        $this->assertSame(40.0, $total);
    }

    public function test_an_activity_switched_off_or_hidden_still_counts(): void
    {
        $off = $this->definition(20, 'Old activity', false);
        $odd = $this->definition(30, 'Branch-private', true, 'branch');
        $this->entry($this->agent, '2026-10-01', 2, $off);
        $this->entry($this->agent, '2026-10-02', 1, $odd);

        $this->assertSame(70.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)));
    }

    public function test_a_fractional_weight_is_counted_exactly(): void
    {
        $def = $this->definition(0.5);
        $this->entry($this->agent, '2026-10-01', 3, $def);

        $this->assertSame(1.5, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)));
        $this->assertSame(1.5, ActivityPointsService::pointsFor(3, '0.50'));
    }

    public function test_another_agencys_entries_are_never_counted(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . Str::random(6)]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        // A logged-in user's agency is force-stamped on create, so the other agency's agent is made with nobody logged in.
        auth()->guard()->forgetUser();
        $stranger = $this->user('agent', $other, $otherBranch);
        $this->actingAs($this->agent);
        $def = $this->definition(10);
        $this->entry($this->agent, '2026-10-01', 1, $def);
        $this->entry($stranger, '2026-10-01', 9, $def);

        $this->assertSame(10.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)));
        $this->assertSame(90.0, ActivityPointsService::sum(ActivityPointsService::counted($other->id)));
    }

    public function test_an_unreadable_alias_is_refused_not_interpolated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ActivityPointsService::counted(1, 'e; DROP TABLE users', 'd');
    }

    // ── the same number everywhere ───────────────────────────────────────────────────────

    public function test_the_agent_daily_page_my_portal_and_the_dashboard_snapshot_agree(): void
    {
        $this->scenario();

        $page = $this->get('/agent/daily?date=2026-10-20')->assertOk();
        $this->assertSame(130, (int) $page->viewData('mtdPoints'));

        $this->assertEqualsWithDelta(130.0, app(MyPortalNumbers::class)->points($this->agent, '2026-10')['month'], 0.001);

        $snap = app(AgentPerformanceService::class)->getMonthlySnapshot($this->agent, Carbon::parse('2026-10-01'));
        $this->assertEqualsWithDelta(130.0, (float) ($snap['actuals']['points_actual'] ?? $snap['actuals']['points'] ?? 0), 0.001);
    }

    public function test_the_three_summaries_total_the_same_points(): void
    {
        $this->scenario();
        $manager = $this->user('branch_manager');
        $admin = $this->user('admin');
        $q = ['range' => 'month', 'month' => '2026-10'];

        $agentView = $this->actingAs($this->agent)->get(route('agent.daily.summary', $q))->assertOk();
        $this->assertEqualsWithDelta(130.0, $agentView->viewData('grandPoints'), 0.001);

        $bmView = $this->actingAs($manager)->get(route('bm.daily.summary', $q))->assertOk();
        $this->assertEqualsWithDelta(130.0, $bmView->viewData('grandPoints'), 0.001);

        $adminView = $this->actingAs($admin)->get(route('admin.daily.summary', $q))->assertOk();
        $this->assertEqualsWithDelta(130.0, $adminView->viewData('grandPoints'), 0.001);
    }

    public function test_the_company_rollup_totals_the_same_points_for_the_agent(): void
    {
        $this->scenario();

        $period = app(CompanyPerformanceService::class)->getPeriodRollup('2026-10', $this->agency->id);
        $row = collect($period['rows'])->firstWhere('user_id', $this->agent->id);
        $this->assertNotNull($row, 'the agent is a row of the company rollup');
        $this->assertEqualsWithDelta(130.0, (float) $row['actuals']['points'], 0.001);

        $branch = app(CompanyPerformanceService::class)->getBranchRollup($this->branch->id, '2026-10', $this->agency->id);
        $this->assertEqualsWithDelta(130.0, (float) $branch['points']['actual'], 0.001);
    }

    // ── credit on a switched-off activity still shows, and its drill-down opens ──────────

    public function test_summary_lists_credit_on_a_switched_off_activity_and_its_drilldown_opens(): void
    {
        [, $off] = $this->scenario();

        $view = $this->get(route('agent.daily.summary', ['range' => 'month', 'month' => '2026-10']))->assertOk();
        $names = collect($view->viewData('items'))->pluck('name')->all();
        $this->assertContains('[Auto] mandate.signed', $names, 'credit earned on a switched-off activity stays listed');

        $this->get(route('agent.daily.summary.activity', ['definition' => $off, 'range' => 'month', 'month' => '2026-10']))
            ->assertOk()
            ->assertViewHas('totalPoints', 100.0);
    }

    public function test_a_switched_off_activity_with_no_credit_and_a_foreign_activity_answer_404(): void
    {
        $nobodyEarned = $this->definition(10, 'Never used', false);
        $this->get(route('agent.daily.summary.activity', ['definition' => $nobodyEarned]))->assertNotFound();
        $this->get(route('agent.daily.summary.activity', ['definition' => 987654]))->assertNotFound();
    }

    // ── strips that read the abandoned table now read the live entries ───────────────────

    public function test_the_company_and_agent_strips_read_the_live_entries(): void
    {
        [$offered] = $this->scenario();
        $this->entry($this->agent, '2026-10-20', 4, $offered); // today: 40 + 100 (the switched-off credit from scenario())

        $svc = app(CompanyPerformanceService::class);
        $branch = $svc->getBranchRollup($this->branch->id, '2026-10', $this->agency->id);
        $today = collect($branch['momentum_7d'])->last();
        $this->assertSame('2026-10-20', $today['date']);
        $this->assertEqualsWithDelta(140.0, $today['points'], 0.001);
        $this->assertEquals(['[Auto] mandate.signed' => 2, 'Call an owner' => 4], $branch['activities_today']);

        $agent = $svc->getAgentRollup($this->branch->id, $this->agent->id, '2026-10', $this->agency->id);
        $this->assertEqualsWithDelta(140.0, collect($agent['momentum_7d'])->last()['points'], 0.001);
        $this->assertSame(4, $agent['activities_today']['Call an owner']);

        $snap = app(AgentPerformanceService::class)->getMonthlySnapshot($this->agent, Carbon::parse('2026-10-01'));
        $this->assertArrayHasKey('2026-10-05', $snap['daily_map']);
        $this->assertSame(1, $snap['daily_map']['2026-10-05']['daily_rows']);
    }

    public function test_a_day_with_nothing_counted_is_an_empty_strip_not_an_error(): void
    {
        $branch = app(CompanyPerformanceService::class)->getBranchRollup($this->branch->id, '2026-10', $this->agency->id);
        $this->assertCount(7, $branch['momentum_7d']);
        $this->assertSame([], $branch['activities_today']);
    }

    // ── the data move ────────────────────────────────────────────────────────────────────

    public function test_the_migration_rebases_calendar_credit_to_counts_without_touching_confirmed_points(): void
    {
        $def = $this->definition(200, 'Appointments');
        $other = $this->definition(50, 'Take out Buyers');
        $map = fn (int $d, string $class, int $vpe, string $kind = 'calendar') => DB::table('activity_definition_calendar_classes')->insertGetId([
            'agency_id' => $this->agency->id, 'event_class' => $class, 'activity_definition_id' => $d, 'trigger_kind' => $kind,
            'value_per_event' => $vpe, 'requires_feedback' => 1, 'auto_revoke_after_hours' => 24, 'back_date_limit_hours' => 48,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $seeded = $map($def, 'property_evaluation', 200);
        $agencyChose = $map($other, 'viewing', 3);
        $instant = $map($def, 'x', 1, 'instant');

        $this->entry($this->agent, '2026-10-01', 200, $def, 'confirmed', 'auto_calendar');   // past, confirmed: untouched
        $this->entry($this->agent, '2026-10-02', 200, $def, 'overridden', 'auto_calendar');  // untouched
        $this->entry($this->agent, '2026-10-03', 200, $def, 'revoked', 'auto_calendar');    // untouched
        $this->entry($this->agent, '2026-10-04', 200, $def, 'provisional', 'auto_calendar'); // re-based
        $this->entry($this->agent, '2026-10-05', 3, $other, 'provisional', 'auto_calendar'); // an agency-chosen count: untouched
        $this->entry($this->agent, '2026-10-06', 200, $def, 'provisional', 'manual');        // not calendar: untouched

        $run = fn () => (require base_path('database/migrations/2026_11_26_100000_rebase_calendar_auto_credit_to_counts.php'))->up();
        $run();
        $run(); // idempotent

        $this->assertSame(1, (int) DB::table('activity_definition_calendar_classes')->where('id', $seeded)->value('value_per_event'));
        $this->assertSame(3, (int) DB::table('activity_definition_calendar_classes')->where('id', $agencyChose)->value('value_per_event'));
        $this->assertSame(1, (int) DB::table('activity_definition_calendar_classes')->where('id', $instant)->value('value_per_event'));

        $value = fn (string $date) => (int) DB::table('daily_activity_entries')->where('activity_date', $date)->value('value');
        $this->assertSame(200, $value('2026-10-01'));
        $this->assertSame(200, $value('2026-10-02'));
        $this->assertSame(200, $value('2026-10-03'));
        $this->assertSame(1, $value('2026-10-04'));
        $this->assertSame(3, $value('2026-10-05'));
        $this->assertSame(200, $value('2026-10-06'));
    }

    // ── menu: the capture entries left the Agency Tracker panel ──────────────────────────

    public function test_the_agency_tracker_panel_has_no_capture_entry_and_the_form_still_opens(): void
    {
        $html = $this->get('/agent/daily?date=2026-10-20')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('~corex-nav-subitem[^>]*>\s*(My Daily Activity|Daily Activity Capture)\s*<~', $html);
        $this->assertMatchesRegularExpression('~corex-nav-item active"[^>]*>.*?My Portal~s', $html, 'My Portal is the highlighted home of the capture form');

        $this->get(route('agent.daily.print', ['date' => '2026-10-20']))->assertOk();
    }
}
