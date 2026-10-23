<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Activity\ActivityPointsService;
use App\Services\PermissionService;
use App\Services\RoadToSold\DailyActivityMigrationService;
use App\Services\RoadToSold\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 3 - the old daily-activity screens are replaced for an agency that has been cut over
 * (road-to-sold.md section 7.1, 7.7, 10.4, 14 phase 3).
 *
 * Proven: capture, print and save, the three summaries and their drill-downs, and the definitions setup all redirect to their Road to Sold
 * home for a cut-over agency, and the old route names keep resolving; the Auto tab of Setup stays; an agency not cut over keeps every old
 * screen; a person who cannot open Road to Sold is not redirected into a refusal; the Team view lists every agent inside the viewer's
 * breadth (a branch manager: their branch only; an admin: the agency; an agent: no team view) with points split manual / automatic from
 * the one shared rule, search / filter / sort / pages / empty state, and an agent outside the breadth is a 404; the page header shows the
 * month's points from the same rule, split.
 */
final class DailyActivityRetireTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $agent;
    private User $agentSouth;
    private int $def;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        ActivityPointsService::forgetCutovers();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', config('app.timezone')));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agent = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth = $this->mk('Shawn Du Bois', 'agent', $this->south);

        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.manage_recipe', null],
            ['admin', 'access_daily_activity', null], ['admin', 'view_daily_activity', null], ['admin', 'daily_activity.view', 'all'],
            ['admin', 'manage_targets', null], ['admin', 'manage_activity_mappings', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['branch_manager', 'access_daily_activity', null], ['branch_manager', 'view_daily_activity', null], ['branch_manager', 'daily_activity.view', 'branch'],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
            ['agent', 'access_daily_activity', null], ['agent', 'view_daily_activity', null], ['agent', 'daily_activity.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        $this->def = (int) DB::table('activity_definitions')->insertGetId(['name' => 'Call an owner', 'weight' => 10, 'sort_order' => 1, 'scope' => 'system', 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $auto = (int) DB::table('activity_definitions')->insertGetId(['name' => '[Auto] contact.captured', 'weight' => 50, 'sort_order' => 2, 'scope' => 'system', 'is_enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->entry($this->agent, $this->def, 3, 'manual');
        $this->entry($this->agent, $auto, 2, 'auto_instant');
        $this->entry($this->agentSouth, $this->def, 5, 'manual');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ActivityPointsService::forgetCutovers();
        parent::tearDown();
    }

    private function mk(string $name, string $role, Branch $b): User
    {
        return User::factory()->create([
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function entry(User $u, int $def, int $value, string $source): void
    {
        DB::table('daily_activity_entries')->insert([
            'activity_date' => '2026-10-20', 'period' => '2026-10', 'user_id' => $u->id, 'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id,
            'activity_definition_id' => $def, 'value' => $value, 'point_state' => 'confirmed', 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cutOver(): void
    {
        app(DailyActivityMigrationService::class)->apply($this->agency->id);
        PermissionService::clearCache();
    }

    // ── The old addresses ─────────────────────────────────────────────────

    public function test_the_old_screens_redirect_to_their_road_to_sold_home_for_a_cut_over_agency(): void
    {
        $this->cutOver();
        $index = route('road-to-sold.index');

        $this->actingAs($this->agent)->get(route('agent.daily'))->assertRedirect($index);
        $this->actingAs($this->agent)->get(route('agent.daily.print'))->assertRedirect($index);
        $this->actingAs($this->agent)->post('/agent/daily', [])->assertRedirect($index);
        $this->actingAs($this->agent)->get(route('agent.daily.summary'))->assertRedirect($index . '?view=captures');
        $this->actingAs($this->agent)->get(route('agent.daily.summary.activity', 1))->assertRedirect($index . '?view=captures');
        $this->actingAs($this->manager)->get(route('bm.daily.summary'))->assertRedirect($index . '?view=team');
        $this->actingAs($this->manager)->get(route('bm.daily.summary.activity', 1))->assertRedirect($index . '?view=team');
        $this->actingAs($this->admin)->get(route('admin.daily.summary'))->assertRedirect($index . '?view=team');
        $this->actingAs($this->admin)->get(route('admin.daily-activities.setup'))->assertRedirect(route('road-to-sold.settings.recipe.index'));
        $this->actingAs($this->admin)->get(route('admin.targets.activity.setup'))->assertRedirect(route('admin.daily-activities.setup', ['tab' => 'manual']));
    }

    public function test_it_is_a_302_never_a_301_because_the_same_address_means_different_things_to_different_agencies(): void
    {
        $this->cutOver();

        $this->assertSame(302, $this->actingAs($this->agent)->get(route('agent.daily'))->getStatusCode());
    }

    public function test_the_automatic_credit_rules_stay_on_the_old_setup_screen(): void
    {
        $this->cutOver();

        $this->actingAs($this->admin)->get(route('admin.daily-activities.setup', ['tab' => 'auto']))->assertOk();
    }

    public function test_an_agency_that_has_not_been_cut_over_keeps_every_old_screen(): void
    {
        $this->actingAs($this->agent)->get(route('agent.daily'))->assertOk();
        $this->actingAs($this->agent)->get(route('agent.daily.summary'))->assertOk();
        $this->actingAs($this->manager)->get(route('bm.daily.summary'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.daily.summary'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.daily-activities.setup'))->assertOk();
    }

    public function test_someone_who_cannot_open_road_to_sold_is_not_sent_into_a_refusal(): void
    {
        $this->cutOver();
        RolePermission::where('role', 'agent')->where('permission_key', 'access_road_to_sold')->delete();
        PermissionService::clearCache();

        $res = $this->actingAs($this->agent)->get(route('agent.daily'));

        $this->assertNotSame(route('road-to-sold.index'), $res->headers->get('Location'));
    }

    // ── The Team view ─────────────────────────────────────────────────────

    private function teamRows($res): array
    {
        return collect($res->viewData('teamTotals'))->pluck('name')->all();
    }

    public function test_the_team_view_lists_the_agents_inside_the_viewers_breadth_with_points_split_from_the_one_rule(): void
    {
        $this->cutOver();

        $res = $this->actingAs($this->admin)->get(route('road-to-sold.index', ['view' => 'team']));
        $res->assertOk();
        $totals = collect($res->viewData('teamTotals'))->keyBy('user_id');
        $this->assertEqualsCanonicalizing(['Retha Kelly', 'Shawn Du Bois'], $this->teamRows($res));
        $this->assertSame(['manual' => 30.0, 'automatic' => 100.0, 'total' => 130.0], collect($totals[$this->agent->id])->only(['manual', 'automatic', 'total'])->all());
        $this->assertSame(130.0, ActivityPointsService::sum(ActivityPointsService::counted($this->agency->id)->where('e.user_id', $this->agent->id)), 'the same number every other screen shows');
        $this->assertSame(50.0, $totals[$this->agentSouth->id]['total']);
        $res->assertSee('Manual');
        $res->assertSee('Automatic');

        $this->assertSame(['Retha Kelly'], $this->teamRows($this->actingAs($this->manager)->get(route('road-to-sold.index', ['view' => 'team']))), 'a branch manager sees their branch only');
    }

    public function test_an_agent_has_no_team_view_and_cannot_open_a_colleague(): void
    {
        $this->cutOver();

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['view' => 'team']));
        $res->assertOk();
        $this->assertFalse($res->viewData('isTeam'));
        $this->assertSame([], $res->viewData('teamTotals'));
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['agent' => $this->agentSouth->id, 'view' => 'team']))->assertNotFound();
    }

    public function test_the_team_list_searches_filters_sorts_pages_and_has_an_empty_state(): void
    {
        $this->cutOver();
        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('road-to-sold.index', ['view' => 'team'] + $q));
        $agents = fn ($res) => collect($res->viewData('captures')->items())->pluck('user_id')->unique()->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$this->agent->id, $this->agentSouth->id], $agents($get([])));
        $this->assertSame([$this->agent->id], $agents($get(['agent_filter' => $this->agent->id])));
        $this->assertSame([$this->agentSouth->id], $agents($get(['q' => 'Call an owner', 'agent_filter' => $this->agentSouth->id])));
        $this->assertSame([$this->agent->id], $agents($get(['source' => 'migrated'] + ['agent_filter' => $this->agent->id])));
        $get(['q' => 'nothing like this'])->assertSee('Nothing matches this search.');
        $this->assertSame([], $get(['from' => '2026-12-01'])->viewData('captures')->items());
        $get([])->assertSee('Retha Kelly')->assertSee('Shawn Du Bois');
        $this->assertSame(25, $get([])->viewData('captures')->perPage());
    }

    public function test_the_team_totals_follow_the_date_range(): void
    {
        $this->cutOver();

        $inRange = $this->actingAs($this->admin)->get(route('road-to-sold.index', ['view' => 'team', 'from' => '2026-10-20', 'to' => '2026-10-20']));
        $this->assertCount(2, $inRange->viewData('teamTotals'));
        $outRange = $this->actingAs($this->admin)->get(route('road-to-sold.index', ['view' => 'team', 'from' => '2026-09-01', 'to' => '2026-09-30']));
        $this->assertSame([], $outRange->viewData('teamTotals'));
        $outRange->assertSee('No points in this period.');
    }

    // ── The page header ───────────────────────────────────────────────────

    public function test_the_page_header_shows_the_months_points_from_the_shared_rule_split_for_either_kind_of_agency(): void
    {
        // not cut over: read from the old rows
        $this->recipeStarted();
        $before = $this->actingAs($this->agent)->get(route('road-to-sold.index'));
        $this->assertSame(130.0, $before->viewData('progress')['points']);
        $this->assertSame(['manual' => 30.0, 'automatic' => 100.0, 'total' => 130.0], $before->viewData('progress')['points_split']);

        $this->cutOver();
        $after = $this->actingAs($this->agent)->get(route('road-to-sold.index'));
        $this->assertSame(130.0, $after->viewData('progress')['points'], 'the same number after the move');
        $after->assertSee('points in');
        $after->assertSee('Manual');
        $after->assertSee('Automatic');
    }

    private function recipeStarted(): void
    {
        app(\App\Services\RoadToSold\RecipeService::class)->start($this->agency->id, 'sales');
    }
}
