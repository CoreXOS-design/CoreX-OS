<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DailyActivityEntry;
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
 * Johan, 21 Oct 2026: show the points as MANUAL (captured by the agent) and AUTOMATIC (credited by CoreX work),
 * together but separate, everywhere points are shown - from the shared points rule, never a second calculation.
 *
 * Input paths proven: manual + automatic always equals the combined total the shared rule gives, over every
 * state x source (provisional / revoked / auto_other count for neither part); the split by day and the calendar
 * markers; a fractional weight; no points at all (zero parts, not an error); another agency never mixed in; the
 * same split on My Portal (month, today, strips), the daily page, the dashboard snapshot, the three summaries
 * (per activity and in total) and the branch / company rollups.
 */
final class PointsManualAutomaticSplitTest extends TestCase
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

    private function user(string $role): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function def(float $weight, string $name = null): int
    {
        return (int) DB::table('activity_definitions')->insertGetId(['name' => $name ?? 'Call ' . Str::random(5), 'weight' => $weight, 'sort_order' => 1, 'scope' => 'system', 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(string $date, int $value, int $def, string $source = 'manual', string $state = 'confirmed', ?User $u = null): void
    {
        $u ??= $this->agent;
        DB::table('daily_activity_entries')->insert([
            'activity_date' => $date, 'period' => substr($date, 0, 7), 'user_id' => $u->id, 'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id,
            'activity_definition_id' => $def, 'value' => $value, 'point_state' => $state, 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** manual 3x10=30 + 2x5=10 (40); automatic calendar 1x50 + instant 4x5=20 (70); total 110. Noise that counts for neither part. */
    private function scenario(): void
    {
        $call = $this->def(10, 'Call an owner');
        $card = $this->def(5, 'Hand out cards');
        $viewing = $this->def(50, 'Viewing');
        $this->entry('2026-10-20', 3, $call);
        $this->entry('2026-10-19', 2, $card);
        $this->entry('2026-10-20', 1, $viewing, 'auto_calendar');
        $this->entry('2026-10-19', 4, $card, 'auto_instant');
        $this->entry('2026-10-18', 9, $call, 'manual', 'provisional');      // not yet earned
        $this->entry('2026-10-18', 9, $call, 'auto_instant', 'revoked');     // reversed
        $this->entry('2026-10-18', 9, $call, 'auto_other', 'confirmed');     // not a scored source
    }

    private function counted(): \Illuminate\Database\Query\Builder
    {
        return ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id);
    }

    // ── the rule ─────────────────────────────────────────────────────────────────────────

    public function test_manual_plus_automatic_is_exactly_the_combined_total_of_the_shared_rule(): void
    {
        $this->scenario();

        $split = ActivityPointsService::sumSplit($this->counted());
        $this->assertSame(40.0, $split['manual']);
        $this->assertSame(70.0, $split['automatic']);
        $this->assertSame(110.0, $split['total']);
        $this->assertSame(ActivityPointsService::sum($this->counted()), $split['total'], 'one rule: the split adds up to sum()');
        $this->assertEqualsCanonicalizing(DailyActivityEntry::ACHIEVEMENT_TOTAL_SOURCES, array_merge(DailyActivityEntry::MANUAL_SOURCES, DailyActivityEntry::AUTOMATIC_SOURCES));
    }

    public function test_the_split_by_day_and_the_calendar_markers(): void
    {
        $this->scenario();

        $byDay = ActivityPointsService::sumByDateSplit($this->counted());
        $this->assertSame(['manual' => 30.0, 'automatic' => 50.0, 'total' => 80.0], $byDay['2026-10-20']);
        $this->assertSame(['manual' => 10.0, 'automatic' => 20.0, 'total' => 30.0], $byDay['2026-10-19']);
        $this->assertArrayNotHasKey('2026-10-18', $byDay, 'a day with only unearned credit has no points');

        $markers = ActivityPointsService::entryRowsByDateSplit($this->counted());
        $this->assertSame(['manual' => 1, 'automatic' => 1], $markers['2026-10-20']);
        $this->assertSame(['manual' => 1, 'automatic' => 1], $markers['2026-10-19']);
    }

    public function test_a_fractional_weight_and_nothing_at_all(): void
    {
        $this->assertSame(['manual' => 0.0, 'automatic' => 0.0, 'total' => 0.0], ActivityPointsService::sumSplit($this->counted()));
        $this->assertSame([], ActivityPointsService::sumByDateSplit($this->counted()));

        $half = $this->def(0.5);
        $this->entry('2026-10-20', 3, $half);
        $this->entry('2026-10-20', 5, $half, 'auto_instant');
        $this->assertSame(['manual' => 1.5, 'automatic' => 2.5, 'total' => 4.0], ActivityPointsService::sumSplit($this->counted()));
        $this->assertSame(['manual' => 1.5, 'automatic' => 2.5, 'total' => 4.0], ActivityPointsService::splitPointsFor(3, 5, '0.50'));
    }

    public function test_another_agencys_credit_is_never_mixed_in(): void
    {
        $this->scenario();
        auth()->guard()->forgetUser();
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . Str::random(6)]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'agent', 'is_active' => true]);
        $this->entry('2026-10-20', 100, $this->def(10), 'manual', 'confirmed', $stranger);
        $this->actingAs($this->agent);

        $this->assertSame(110.0, ActivityPointsService::sumSplit(ActivityPointsService::counted($this->agency->id))['total']);
    }

    // ── My Portal ────────────────────────────────────────────────────────────────────────

    public function test_my_portal_points_carry_the_split_and_it_adds_up(): void
    {
        $this->scenario();

        $p = app(MyPortalNumbers::class)->points($this->agent, '2026-10');

        $this->assertSame(110.0, $p['month']);
        $this->assertSame(['manual' => 40.0, 'automatic' => 70.0, 'total' => 110.0], $p['monthSplit']);
        $this->assertSame(['manual' => 30.0, 'automatic' => 50.0, 'total' => 80.0], $p['todaySplit']);
        $this->assertCount(14, $p['daysSplit']);
        foreach ($p['days'] as $day => $pts) {
            $this->assertEqualsWithDelta($pts, $p['daysSplit'][$day]['manual'] + $p['daysSplit'][$day]['automatic'], 0.001, "{$day}: the two parts add up to the day");
        }
    }

    public function test_the_my_activity_and_where_i_am_blocks_show_two_colours_totals_and_a_legend(): void
    {
        $this->scenario();

        $html = $this->get(route('agent.portal', ['sub' => 'myactivity']))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="points-legend"', $html);
        $this->assertMatchesRegularExpression('~data-testid="points-month".*?data-part="total"[^>]*>\s*110\s*<.*?Manual 40.*?Automatic 70~s', $html);
        $this->assertMatchesRegularExpression('~data-testid="points-today".*?data-part="total"[^>]*>\s*80\s*<.*?Manual 30.*?Automatic 50~s', $html);
        $this->assertMatchesRegularExpression('~data-day="2026-10-20" data-manual="30" data-automatic="50"~', $html);
        $this->assertStringContainsString('data-testid="momentum-strip"', $html);
        $this->assertMatchesRegularExpression('~data-testid="points-split".*?Manual 40.*?Automatic 70~s', $html);
    }

    // ── the daily page ───────────────────────────────────────────────────────────────────

    public function test_the_daily_page_splits_month_to_date_and_marks_the_calendar_strip(): void
    {
        $this->scenario();

        $page = $this->get('/agent/daily?date=2026-10-20')->assertOk();

        $this->assertSame(110, (int) $page->viewData('mtdPoints'));
        $this->assertSame(['manual' => 40.0, 'automatic' => 70.0, 'total' => 110.0], $page->viewData('mtdSplit'));
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('~data-testid="mtd-split".*?Manual 40.*?Auto 70~s', $html);
        $this->assertMatchesRegularExpression('~data-day="2026-10-20" data-manual="1" data-automatic="1"~', $html);
        $this->assertMatchesRegularExpression('~data-day="2026-10-18" data-manual="0" data-automatic="0"~', $html, 'a day with nothing earned has no marker');
        $this->assertStringContainsString('data-testid="points-legend"', $html);
    }

    // ── the dashboard snapshot ───────────────────────────────────────────────────────────

    public function test_the_agent_dashboard_snapshot_carries_the_split(): void
    {
        $this->scenario();

        $snap = app(AgentPerformanceService::class)->getMonthlySnapshot($this->agent, Carbon::parse('2026-10-01'));

        $this->assertSame(110.0, (float) $snap['actuals']['points_actual']);
        $this->assertSame(['manual' => 40.0, 'automatic' => 70.0, 'total' => 110.0], $snap['points_split']);
        $this->assertSame(['manual' => 30.0, 'automatic' => 50.0, 'total' => 80.0], $snap['points_by_date_split']['2026-10-20']);
        $this->assertSame(80.0, (float) $snap['points_by_date']['2026-10-20']);
        $this->assertSame(['daily_rows' => 2, 'manual_rows' => 1, 'auto_rows' => 1, 'deals' => 0, 'value' => 0], $snap['daily_map']['2026-10-20']);
    }

    // ── the three summaries ──────────────────────────────────────────────────────────────

    public function test_the_three_summaries_split_each_activity_and_the_total(): void
    {
        $this->scenario();
        $manager = $this->user('branch_manager');
        $admin = $this->user('admin');
        $q = ['range' => 'month', 'month' => '2026-10'];

        foreach ([
            [$this->agent, route('agent.daily.summary', $q)],
            [$manager, route('bm.daily.summary', $q)],
            [$admin, route('admin.daily.summary', $q)],
        ] as [$as, $url]) {
            $page = $this->actingAs($as)->get($url)->assertOk();
            $this->assertSame(['manual' => 40.0, 'automatic' => 70.0, 'total' => 110.0], $page->viewData('grandSplit'), $url);
            $this->assertEqualsWithDelta($page->viewData('grandPoints'), $page->viewData('grandSplit')['total'], 0.001);
            foreach ($page->viewData('items') as $it) {
                $this->assertEqualsWithDelta($it['points'], $it['manual_points'] + $it['auto_points'], 0.001, $it['name'] . ': the parts add up to the activity');
            }
            $items = collect($page->viewData('items'))->keyBy('name');
            $this->assertEqualsWithDelta(30, $items['Call an owner']['manual_points'], 0.001);
            $this->assertEqualsWithDelta(50, $items['Viewing']['auto_points'], 0.001);
            $this->assertEqualsWithDelta(20, $items['Hand out cards']['auto_points'], 0.001);
            $html = $page->getContent();
            $this->assertStringContainsString('data-testid="points-legend"', $html);
            $this->assertMatchesRegularExpression('~data-testid="grand-split".*?Manual 40.*?Automatic 70~s', $html);
            $this->assertStringContainsString('data-part="source-split"', $html);
        }
    }

    // ── branch and company rollups ───────────────────────────────────────────────────────

    public function test_branch_and_company_rollups_split_rows_totals_and_the_momentum_strip(): void
    {
        $this->scenario();
        $svc = app(CompanyPerformanceService::class);

        $period = $svc->getPeriodRollup('2026-10', $this->agency->id);
        $row = collect($period['rows'])->firstWhere('user_id', $this->agent->id);
        $this->assertSame([110.0, 40.0, 70.0], [(float) $row['actuals']['points'], (float) $row['actuals']['points_manual'], (float) $row['actuals']['points_auto']]);
        $this->assertSame([40.0, 70.0], [(float) $period['totals']['actuals']['points_manual'], (float) $period['totals']['actuals']['points_auto']]);

        $branch = $svc->getBranchRollup($this->branch->id, '2026-10', $this->agency->id);
        $this->assertSame([110.0, 40.0, 70.0], [(float) $branch['points']['actual'], (float) $branch['points']['manual'], (float) $branch['points']['automatic']]);
        $last = collect($branch['momentum_7d'])->last();
        $this->assertSame(['2026-10-20', 80.0, 30.0, 50.0], [$last['date'], $last['points'], $last['manual'], $last['automatic']]);

        $agent = $svc->getAgentRollup($this->branch->id, $this->agent->id, '2026-10', $this->agency->id);
        $lastAgent = collect($agent['momentum_7d'])->last();
        $this->assertSame([80.0, 30.0, 50.0], [$lastAgent['points'], $lastAgent['manual'], $lastAgent['automatic']]);
    }

    public function test_performance_pages_and_dashboards_render_the_split_without_errors(): void
    {
        $this->scenario();
        $manager = $this->user('branch_manager');
        $admin = $this->user('admin');

        $this->actingAs($manager)->get(route('bm.performance', ['period' => '2026-10']))->assertOk()->assertSee('Manual', false);
        $this->actingAs($admin)->get(route('admin.performance', ['period' => '2026-10']))->assertOk();
        $this->actingAs($admin)->get(route('admin.branch.performance', ['branchId' => $this->branch->id, 'period' => '2026-10']))->assertOk()->assertSee('data-part="manual"', false);
        $this->actingAs($manager)->get(route('bm.agent.performance', ['userId' => $this->agent->id, 'period' => '2026-10']))->assertOk()->assertSee('points-legend', false);
        $dash = $this->actingAs($this->agent)->get(route('agent.dashboard', ['period' => '2026-10']))->assertOk();
        $this->assertMatchesRegularExpression('~data-testid="points-split".*?Manual 40\.0.*?Automatic 70\.0~s', $dash->getContent());
        $this->assertMatchesRegularExpression('~data-day="2026-10-20" data-manual="30" data-automatic="50"~', $dash->getContent());
        $this->actingAs($this->agent)->get(route('command-center.performance'))->assertOk()->assertSee('data-part="manual"', false);
    }
}
