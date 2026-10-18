<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\SeedsDerivedAreas;
use Tests\TestCase;

/**
 * AT-454 - Road to Sold areas (.ai/specs/road-to-sold.md sections 2.3, 3.2, 5.2, 5.4, 6).
 *
 * Input paths proven: pool = the agency's own towns' suburbs only (another agency's excluded);
 * add / add-again (no-op) / add a suburb outside the pool (refused, nothing written);
 * remove then give back, and remove then re-add (the unique-index + soft-delete pass, BUILD_STANDARD 5a);
 * an assignment whose suburb left the pool; direct-URL by id from another branch and another agency -> 404;
 * search, town, "no suburbs" and "needs a refresh" filters; both empty states; last-captured per portal;
 * the lock-on warning; a manage_areas-only user landing on their tab; an agent refused by direct URL.
 */
final class AreasTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDerivedAreas;

    private Agency $agency;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $managerNorth;
    private User $agentNorth;
    private User $agentSouth;
    private int $cityMargate;
    private int $cityPort;
    private array $sub = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->north  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->south  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);

        $mk = fn (string $name, string $role, Branch $b) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
        $this->admin        = $mk('Alice Admin', 'admin', $this->north);
        $this->managerNorth = $mk('Bruce Manager', 'branch_manager', $this->north);
        $this->agentNorth   = $mk('Retha Kelly', 'agent', $this->north);
        $this->agentSouth   = $mk('Shawn Du Bois', 'agent', $this->south);

        // A populated grants table (empty permits everything in tests).
        foreach ([
            ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'],
            ['admin', 'road_to_sold.manage_areas', null], ['admin', 'road_to_sold.manage_settings', null],
            ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'],
            ['branch_manager', 'road_to_sold.manage_areas', null],
            ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
        ] as [$role, $key, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();

        // Locations: two cities, the agency has a town on Margate only.
        $country  = DB::table('p24_countries')->insertGetId(['p24_id' => 9001, 'name' => 'South Africa']);
        $province = DB::table('p24_provinces')->insertGetId(['p24_id' => 9001, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal']);
        $this->cityMargate = DB::table('p24_cities')->insertGetId(['p24_id' => 9011, 'p24_province_id' => $province, 'name' => 'Margate']);
        $this->cityPort    = DB::table('p24_cities')->insertGetId(['p24_id' => 9012, 'p24_province_id' => $province, 'name' => 'Port Shepstone']);

        $this->sub['uvongo']  = $this->suburb('Uvongo', $this->cityMargate, 900101);
        $this->sub['manaba']  = $this->suburb('Manaba', $this->cityMargate, 900102);
        $this->sub['ramsgate'] = $this->suburb('Ramsgate', $this->cityMargate, 900103);
        $this->sub['marina']  = $this->suburb('Marina Beach', $this->cityPort, 900104); // a city the agency does NOT have a town for

        // The agency's working areas are derived from its stock and Market Intelligence (Johan, 13 Oct 2026): it holds
        // adverts in these three, and nothing in Marina Beach.
        foreach (['uvongo', 'manaba', 'ramsgate'] as $k) {
            $this->putInPool($this->agency->id, $this->sub[$k]);
        }
    }

    private function suburb(string $name, int $cityId, int $p24Id): int
    {
        return DB::table('p24_suburbs')->insertGetId([
            'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'p24_id' => $p24Id,
            'p24_city_id' => $cityId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function listing(string $suburb, string $portal, string $seen, string $ref): void
    {
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'portal_source' => $portal,
            'portal_ref' => $ref, 'suburb' => $suburb, 'first_seen_at' => $seen, 'last_seen_at' => $seen,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assign(User $agent, int $suburbId): RtsAgentArea
    {
        return RtsAgentArea::create([
            'agency_id' => $this->agency->id, 'branch_id' => $agent->branch_id, 'track' => 'sales',
            'user_id' => $agent->id, 'p24_suburb_id' => $suburbId, 'assigned_by_user_id' => $this->admin->id,
        ]);
    }

    // ── pool ──────────────────────────────────────────────────────────────

    public function test_the_pool_is_derived_from_what_the_agency_holds_never_from_set_up_towns_or_auto_import(): void
    {
        $svc = app(AreaService::class);
        $ids = $svc->poolQuery($this->agency->id)->pluck('s.id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame(collect([$this->sub['uvongo'], $this->sub['manaba'], $this->sub['ramsgate']])->sort()->values()->all(), $ids);

        // A town hand-set in Prospecting Setup, or a suburb ticked for P24 auto-import, adds NOTHING: only stock and MIC data do.
        DB::table('towns')->insert(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone', 'slug' => 'ps', 'p24_city_id' => $this->cityPort, 'display_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('minion_capture_areas')->insert(['agency_id' => $this->agency->id, 'p24_suburb_id' => $this->sub['marina'], 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['marina']]));
    }

    public function test_another_agencys_data_never_widens_this_agencys_pool(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->putInPool($other->id, $this->sub['marina'], 5);

        $svc = app(AreaService::class);
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['marina']]), 'the first agency does not see the second one\'s suburb');
        $this->assertSame([$this->sub['marina']], $svc->idsInPool($other->id, [$this->sub['marina']]));
        $this->assertNotContains($this->sub['uvongo'], $svc->idsInPool($other->id, [$this->sub['uvongo']]), 'and the second does not see the first one\'s');
    }

    // ── the screen ────────────────────────────────────────────────────────

    public function test_admin_sees_every_agent_and_a_manager_only_their_branch(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertSee('Shawn Du Bois');

        $this->actingAs($this->managerNorth)->get(route('road-to-sold.settings.areas.index'))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
    }

    public function test_the_empty_states_say_why(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['q' => 'zzz-nobody']))
            ->assertOk()->assertSee('No agents match those filters');

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['status' => 'removed']))
            ->assertOk()->assertSee('No agents match those filters');
    }

    public function test_the_add_picker_lists_only_pool_suburbs_and_hides_ones_the_agent_has(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);

        $this->actingAs($this->admin)
            ->get(route('road-to-sold.settings.areas.index', ['add_for' => $this->agentNorth->id]))
            ->assertOk()
            ->assertSee('Add suburbs for Retha Kelly')
            ->assertSee('Manaba')->assertSee('Ramsgate')
            ->assertDontSee('Marina Beach'); // not in the agency's towns
    }

    // ── adding / removing ─────────────────────────────────────────────────

    public function test_adding_suburbs_creates_assignments_with_the_agents_branch_and_adding_again_is_a_no_op(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), [
            'add' => [(string) $this->sub['uvongo'], (string) $this->sub['manaba']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, RtsAgentArea::where('user_id', $this->agentNorth->id)->count());
        $this->assertSame($this->north->id, (int) RtsAgentArea::where('user_id', $this->agentNorth->id)->first()->branch_id);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), [
            'add' => [(string) $this->sub['uvongo']],
        ])->assertSessionHas('success', 'Nothing changed - those suburbs were already set.');
        $this->assertSame(2, RtsAgentArea::where('user_id', $this->agentNorth->id)->count());
    }

    public function test_two_agents_can_share_a_suburb(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['add' => [$this->sub['uvongo']]]);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentSouth->id), ['add' => [$this->sub['uvongo']]]);

        $this->assertSame(2, RtsAgentArea::where('p24_suburb_id', $this->sub['uvongo'])->count());
    }

    public function test_a_suburb_outside_the_pool_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), [
            'add' => [$this->sub['uvongo'], $this->sub['marina']],
        ])->assertSessionHasErrors('add');

        $this->assertSame(0, RtsAgentArea::count(), 'one bad suburb must not half-save the rest');
    }

    public function test_malformed_and_empty_posts_are_handled(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['add' => ['abc']])
            ->assertSessionHasErrors('add.0');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), [])
            ->assertSessionHas('success', 'Nothing changed - those suburbs were already set.');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['add' => [999999]])
            ->assertSessionHasErrors('add');
    }

    public function test_remove_then_give_back_and_remove_then_re_add_restore_the_same_row_without_a_duplicate_key_error(): void
    {
        $area = $this->assign($this->agentNorth, $this->sub['uvongo']);

        // Remove (archive), then see it under "Removed suburbs".
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.areas.archive', $area->id))->assertRedirect();
        $this->assertSoftDeleted('rts_agent_areas', ['id' => $area->id]);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['status' => 'removed']))
            ->assertOk()->assertSee('Uvongo')->assertSee('Give back');

        // Give it back.
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.restore', $area->id))->assertRedirect();
        $this->assertNotSoftDeleted('rts_agent_areas', ['id' => $area->id]);

        // Remove, then add the same suburb through the add flow: the trashed row is restored, no second row, no SQL error.
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.areas.archive', $area->id));
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['add' => [$this->sub['uvongo']]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, RtsAgentArea::withTrashed()->where('user_id', $this->agentNorth->id)->count());
        $this->assertNotSoftDeleted('rts_agent_areas', ['id' => $area->id]);
    }

    public function test_remove_through_the_sync_endpoint_works_too(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['remove' => [$this->sub['uvongo']]])
            ->assertSessionHas('success');
        $this->assertSame(0, RtsAgentArea::where('user_id', $this->agentNorth->id)->count());
    }

    // ── direct URL by id ──────────────────────────────────────────────────

    public function test_a_branch_manager_cannot_touch_another_branchs_agent_or_assignment_by_direct_url(): void
    {
        $southArea = $this->assign($this->agentSouth, $this->sub['uvongo']);

        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.areas.sync', $this->agentSouth->id), ['add' => [$this->sub['manaba']]])
            ->assertNotFound();
        $this->actingAs($this->managerNorth)->delete(route('road-to-sold.settings.areas.archive', $southArea->id))->assertNotFound();
        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.areas.restore', $southArea->id))->assertNotFound();

        $this->assertNotSoftDeleted('rts_agent_areas', ['id' => $southArea->id]);
        $this->assertSame(1, RtsAgentArea::count());
    }

    public function test_another_agencys_assignment_is_invisible_by_id(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
        $row = RtsAgentArea::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'track' => 'sales', 'user_id' => $otherAgent->id,
            'p24_suburb_id' => $this->sub['uvongo'],
        ]);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.areas.archive', $row->id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.sync', $otherAgent->id), ['add' => [$this->sub['uvongo']]])->assertNotFound();
    }

    public function test_an_agent_with_only_the_access_key_is_refused_by_direct_url(): void
    {
        $this->actingAs($this->agentNorth)->get(route('road-to-sold.settings.areas.index'))->assertForbidden();
        $this->actingAs($this->agentNorth)->post(route('road-to-sold.settings.areas.sync', $this->agentNorth->id), ['add' => [$this->sub['uvongo']]])
            ->assertForbidden();
        $this->assertSame(0, RtsAgentArea::count());
    }

    public function test_a_user_who_can_only_manage_areas_lands_on_the_areas_tab(): void
    {
        $this->actingAs($this->managerNorth)->get(route('road-to-sold.settings.index'))
            ->assertRedirect(route('road-to-sold.settings.areas.index'));
    }

    // ── filters ───────────────────────────────────────────────────────────

    public function test_search_town_and_no_suburbs_filters(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['q' => 'uvongo']))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['no_areas' => 1]))
            ->assertOk()->assertSee('Shawn Du Bois')->assertDontSee('Retha Kelly');
        $townId = $this->cityMargate; // a town is its Property24 town id
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['town_id' => $townId]))
            ->assertOk()->assertSee('Retha Kelly')->assertDontSee('Shawn Du Bois');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['sort' => 'areas']))->assertOk();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'sort' => 'stale']))->assertOk();
    }

    public function test_a_search_with_sql_wildcards_does_not_error(): void
    {
        $this->actingAs($this->admin)
            ->get(route('road-to-sold.settings.areas.index', ['add_for' => $this->agentNorth->id, 'suburb_q' => '%_\\']))
            ->assertOk()->assertSee('No suburbs match');
    }

    public function test_the_suburbs_view_shows_who_works_each_suburb_and_nobody_yet(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs']))
            ->assertOk()->assertSee('Retha Kelly')->assertSee('Nobody yet');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'unassigned' => 1]))
            ->assertOk()->assertSee('Manaba')->assertDontSee('Retha Kelly');
    }

    public function test_a_stale_suburb_offers_refresh_now_only_to_someone_who_can_open_the_capture_page(): void
    {
        // Nothing was ever captured for Uvongo, so it needs a refresh.
        $url = route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'q' => 'Uvongo']);

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertDontSee('Refresh now')->assertSee('Needs a refresh');

        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'scope' => null, 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('Refresh now')->assertSee(route('admin.minion.setup'), false);
    }

    public function test_the_areas_screen_carries_data_and_controls_only_and_its_buttons_share_one_size(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'scope' => null, 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        foreach (['agents', 'suburbs'] as $view) {
            $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => $view, 'add_for' => $this->agentNorth->id]))
                ->assertOk()
                ->assertSee('cx-equal-btns', false)
                ->assertDontSee('Several agents can share a suburb')
                ->assertDontSee('Refreshing an area is a manual job');
        }
    }

    // ── service ───────────────────────────────────────────────────────────

    public function test_suburb_ids_names_and_has_areas_for_a_user(): void
    {
        $svc = app(AreaService::class);
        $this->assertFalse($svc->hasAreas($this->agentNorth));
        $this->assertSame([], $svc->suburbIdsFor($this->agentNorth));

        $this->assign($this->agentNorth, $this->sub['uvongo']);
        $this->assign($this->agentNorth, $this->sub['ramsgate']);

        $this->assertTrue($svc->hasAreas($this->agentNorth));
        $this->assertEqualsCanonicalizing([$this->sub['uvongo'], $this->sub['ramsgate']], $svc->suburbIdsFor($this->agentNorth));
        $this->assertEqualsCanonicalizing(['uvongo', 'ramsgate'], $svc->suburbNamesNormalisedFor($this->agentNorth));
        $this->assertEqualsCanonicalizing(['uvongo', 'ramsgate'], $svc->suburbNamesLowerTrimFor($this->agentNorth));
    }

    public function test_an_assignment_outside_the_pool_is_kept_and_flagged_and_a_hidden_suburb_stops_feeding_the_lists(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);
        $this->assign($this->agentNorth, $this->sub['marina']); // no stock, no MIC: not in the derived pool (yet)

        $svc = app(AreaService::class);
        $this->assertEqualsCanonicalizing([$this->sub['uvongo'], $this->sub['marina']], $svc->suburbIdsFor($this->agentNorth), 'nothing is deleted or dropped');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index'))
            ->assertOk()->assertSee('Not in your stock or MIC yet', false);

        // an administrator hides Uvongo: the agent's lists stop using it, the assignment row stays
        $svc->hide($this->agency->id, 'sales', 'suburb', $this->sub['uvongo'], $this->admin->id);
        $this->assertSame([$this->sub['marina']], $svc->suburbIdsFor($this->agentNorth));
        $this->assertSame(2, \App\Models\RoadToSold\RtsAgentArea::query()->where('user_id', $this->agentNorth->id)->count());
    }

    public function test_agents_without_areas_lists_only_active_agents_who_may_use_the_page(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);
        $svc = app(AreaService::class);

        $names = $svc->agentsWithoutAreas($this->agency->id)->pluck('name')->all();
        $this->assertContains('Shawn Du Bois', $names);
        $this->assertContains('Bruce Manager', $names);
        $this->assertNotContains('Retha Kelly', $names);

        User::where('id', $this->agentSouth->id)->update(['is_active' => false]);
        $this->assertNotContains('Shawn Du Bois', $svc->agentsWithoutAreas($this->agency->id)->pluck('name')->all());
    }

    public function test_last_captured_takes_the_newest_per_portal_and_the_auto_import_stamp(): void
    {
        $this->listing('Uvongo', 'p24', '2026-10-01 08:00:00', 'A1');
        $this->listing('uvongo ', 'p24', '2026-10-05 08:00:00', 'A2'); // case/space differences still match
        $this->listing('Uvongo', 'pp', '2026-09-20 08:00:00', 'B1');
        DB::table('minion_capture_areas')->insert([
            'agency_id' => $this->agency->id, 'p24_suburb_id' => $this->sub['uvongo'],
            'last_captured_at' => '2026-10-10 02:30:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cap = app(AreaService::class)->lastCaptured($this->agency->id);
        $this->assertSame('2026-10-10 02:30:00', $cap[$this->sub['uvongo']]['p24']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20', $cap[$this->sub['uvongo']]['pp']->format('Y-m-d'));
        $this->assertNull($cap[$this->sub['manaba']]['p24'], 'never captured = null');
        $this->assertNull($cap[$this->sub['manaba']]['pp']);

        $one = app(AreaService::class)->lastRefreshed($this->agency->id, $this->sub['uvongo']);
        $this->assertSame('2026-09-20', $one['pp']->format('Y-m-d'));
    }

    public function test_needs_refresh_fires_when_either_portal_is_stale_or_never_captured(): void
    {
        $svc = app(AreaService::class);
        $this->assertTrue($svc->needsRefresh(['p24' => null, 'pp' => null], 7), 'never captured needs a refresh');
        $this->assertTrue($svc->needsRefresh(['p24' => now()->subDays(2), 'pp' => null], 7), 'a fresh P24 capture does not hide a missing PP one');
        $this->assertTrue($svc->needsRefresh(['p24' => now()->subDays(30), 'pp' => now()->subDays(1)], 7), 'a fresh PP capture does not hide a stale P24 one');
        $this->assertFalse($svc->needsRefresh(['p24' => now()->subDays(2), 'pp' => now()->subDays(3)], 7));
    }

    // ── settings-page warning ─────────────────────────────────────────────

    public function test_the_lock_on_warning_names_agents_with_no_suburbs_and_disappears_when_lock_is_off(): void
    {
        $this->assign($this->agentNorth, $this->sub['uvongo']);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->assertDontSee('they will get no call list');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['lock_agents_to_areas' => '1']);
        SettingsService::forget();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))
            ->assertOk()->assertSee('they will get no call list')->assertSee('Shawn Du Bois')->assertDontSee('Retha Kelly');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['lock_agents_to_areas' => '0']);
        SettingsService::forget();
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->assertDontSee('they will get no call list');
    }

    // ── Johan, 13 Oct 2026: the working areas are derived from stock + Market Intelligence ──────────────────────

    private function stockRow(int $agencyId, int $suburbId, string $status = 'active'): void
    {
        $sub = DB::table('p24_suburbs')->where('id', $suburbId)->first();
        $agent = DB::table('users')->where('agency_id', $agencyId)->value('id');
        \App\Models\Property::withoutEvents(fn () => \App\Models\Property::withoutGlobalScopes()->forceCreate([
            'agency_id' => $agencyId, 'agent_id' => $agent, 'branch_id' => DB::table('users')->where('id', $agent)->value('branch_id'),
            'external_id' => substr(md5(uniqid('', true)), 0, 20), 'title' => 'Stock ' . $sub->name, 'status' => $status, 'listing_type' => 'sale',
            'suburb' => $sub->name, 'p24_suburb_id' => $suburbId, 'p24_city_id' => $sub->p24_city_id,
        ]));
    }

    public function test_own_stock_makes_a_suburb_a_working_area_but_other_agency_stock_never_does(): void
    {
        $svc = app(AreaService::class);
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['marina']]));

        $this->stockRow($this->agency->id, $this->sub['marina'], \App\Models\Property::STATUS_OTHER_AGENCY_STOCK);
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['marina']]), 'a one-off Other Agency Stock import is another agency\'s advert');

        $this->stockRow($this->agency->id, $this->sub['marina']);
        $this->assertSame([$this->sub['marina']], $svc->idsInPool($this->agency->id, [$this->sub['marina']]));
        $this->assertSame(1, $svc->derived($this->agency->id)[$this->sub['marina']]['own']);
    }

    public function test_market_intelligence_adverts_make_a_suburb_a_working_area_and_set_its_last_captured_dates(): void
    {
        $this->putInPool($this->agency->id, $this->sub['marina'], 3, 'pp', '2026-10-01 09:00:00', false);
        $this->putInPool($this->agency->id, $this->sub['marina'], 1, 'p24', '2026-10-12 09:00:00', false);

        $svc = app(AreaService::class);
        $d = $svc->derived($this->agency->id)[$this->sub['marina']];
        $this->assertSame(0, $d['own']);
        $this->assertSame(4, $d['mic']);
        $cap = $svc->lastCaptured($this->agency->id)[$this->sub['marina']];
        $this->assertSame('2026-10-12', $cap['p24']->format('Y-m-d'));
        $this->assertSame('2026-10-01', $cap['pp']->format('Y-m-d'));
        $this->assertSame([$this->sub['marina']], $svc->idsInPool($this->agency->id, [$this->sub['marina']]));
    }

    public function test_an_advert_with_a_city_but_no_suburb_id_is_placed_by_name_inside_that_city_only(): void
    {
        $otherCity = DB::table('p24_suburbs')->insertGetId(['name' => 'Uvongo', 'slug' => 'uvongo-port', 'p24_id' => 900190, 'p24_city_id' => $this->cityPort, 'created_at' => now(), 'updated_at' => now()]);
        $capturer = $this->admin->id;
        $tp = \App\Models\Prospecting\TrackedProperty::withoutEvents(fn () => \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->forceCreate([
            'agency_id' => $this->agency->id, 'external_id' => substr(md5(uniqid('', true)), 0, 20), 'street_number' => '5', 'street_name' => 'Loose Road',
            'suburb' => 'Ramsgate', 'p24_city_id' => $this->cityMargate,
        ]));
        DB::table('prospecting_listings')->insert(['agency_id' => $this->agency->id, 'captured_by_user_id' => $capturer, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24',
            'portal_ref' => 'loose-1', 'suburb' => 'Ramsgate', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $d = app(AreaService::class)->derived($this->agency->id);
        $this->assertSame(1, $d[$this->sub['ramsgate']]['mic'], 'Ramsgate in Margate, placed by name inside its own town');
        $this->assertArrayNotHasKey($otherCity, $d, 'the same name in another town is never guessed');
    }

    public function test_the_minimum_setting_keeps_stray_suburbs_out(): void
    {
        $svc = app(AreaService::class);
        $this->putInPool($this->agency->id, $this->sub['marina'], 2, 'p24', null, false);
        $this->assertContains($this->sub['marina'], $svc->idsInPool($this->agency->id, [$this->sub['marina']]), 'at the standard minimum of 1');

        RtsSetting::create(['agency_id' => $this->agency->id, 'track' => 'sales', 'min_area_properties' => 3]);
        SettingsService::forget();
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['marina']]), '2 adverts is below a minimum of 3');
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['uvongo']]), 'one stock row is below 3 as well');

        $this->putInPool($this->agency->id, $this->sub['marina'], 1, 'p24', null, false);
        $this->assertSame([$this->sub['marina']], $svc->idsInPool($this->agency->id, [$this->sub['marina']]), '3 adverts reaches it');
    }

    public function test_the_minimum_saves_from_the_general_screen_and_the_wizard_and_refuses_zero(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['min_area_properties' => '4'])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(4, SettingsService::forAgency($this->agency->id)->minAreaProperties());

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['min_area_properties' => '0'])->assertSessionHasErrors('min_area_properties');
        SettingsService::forget();
        $this->assertSame(4, SettingsService::forAgency($this->agency->id)->minAreaProperties());
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()->assertSee('A suburb is a working area from');
    }

    public function test_an_administrator_hides_a_suburb_or_a_town_and_brings_it_back_nothing_is_deleted(): void
    {
        $svc = app(AreaService::class);
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.hide'), ['kind' => 'suburb', 'id' => $this->sub['uvongo']])->assertRedirect();
        $this->assertNotContains($this->sub['uvongo'], $svc->idsInPool($this->agency->id, [$this->sub['uvongo']]));
        $this->assertContains($this->sub['manaba'], $svc->idsInPool($this->agency->id, [$this->sub['manaba']]));

        $hidden = $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'status' => 'hidden']))->assertOk()->assertSee('Uvongo')->assertSee('Bring back');
        $row = \App\Models\RoadToSold\RtsHiddenArea::query()->firstOrFail();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.show-again', $row->id))->assertRedirect();
        $this->assertContains($this->sub['uvongo'], $svc->idsInPool($this->agency->id, [$this->sub['uvongo']]));
        $this->assertSame(1, \App\Models\RoadToSold\RtsHiddenArea::withTrashed()->count(), 'the record stays, soft');

        // a whole town: every suburb in it goes, and hiding again restores the same row
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.hide'), ['kind' => 'town', 'id' => $this->cityMargate])->assertRedirect();
        $this->assertSame([], $svc->idsInPool($this->agency->id, [$this->sub['uvongo'], $this->sub['manaba'], $this->sub['ramsgate']]));
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.areas.hide'), ['kind' => 'suburb', 'id' => $this->sub['uvongo']])->assertRedirect();
        $this->assertSame(1, \App\Models\RoadToSold\RtsHiddenArea::withTrashed()->where('kind', 'suburb')->count());
    }

    public function test_only_someone_who_manages_settings_can_hide_and_another_agency_cannot(): void
    {
        $this->actingAs($this->managerNorth)->post(route('road-to-sold.settings.areas.hide'), ['kind' => 'suburb', 'id' => $this->sub['uvongo']])->assertForbidden();
        $this->assertSame(0, \App\Models\RoadToSold\RtsHiddenArea::withTrashed()->count());

        // the hide list is the agency's own: another agency hiding Uvongo does not touch this one
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        \Illuminate\Support\Facades\Auth::logout();
        $this->app['auth']->forgetGuards();
        app(AreaService::class)->hide($other->id, 'sales', 'suburb', $this->sub['uvongo'], null);
        $this->assertSame($other->id, (int) \App\Models\RoadToSold\RtsHiddenArea::withoutGlobalScopes()->value('agency_id'));
        $this->assertContains($this->sub['uvongo'], app(AreaService::class)->idsInPool($this->agency->id, [$this->sub['uvongo']]));
    }

    public function test_the_suburbs_list_shows_where_each_suburb_comes_from_and_sorts_and_filters_on_it(): void
    {
        $this->stockRow($this->agency->id, $this->sub['uvongo']);
        $this->stockRow($this->agency->id, $this->sub['uvongo']);
        $this->putInPool($this->agency->id, $this->sub['manaba'], 7, 'p24', '2026-10-02 08:00:00', false);

        $res = $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'sort' => 'mic']))->assertOk()
            ->assertSee('Our stock')->assertSee('MIC adverts');
        $html = $res->getContent();
        $this->assertLessThan(strpos($html, 'Uvongo', strpos($html, 'data-qa="suburb-row"')), strpos($html, 'Manaba', strpos($html, 'data-qa="suburb-row"')), 'most MIC adverts first');

        $this->putInPool($this->agency->id, $this->sub['marina'], 4, 'pp', '2026-10-03 08:00:00', false);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'source' => 'mic']))->assertOk()
            ->assertSee('Marina Beach')->assertDontSee('Uvongo');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'source' => 'stock']))->assertOk()
            ->assertSee('Uvongo')->assertDontSee('Marina Beach');
    }

    public function test_an_agency_with_no_stock_and_no_mic_gets_one_line_and_the_ways_to_fill_it_never_another_agencys_suburbs(): void
    {
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'scope' => null, 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
        DB::table('properties')->where('agency_id', $this->agency->id)->delete();
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->putInPool($other->id, $this->sub['marina'], 9);

        foreach (['agents', 'suburbs'] as $view) {
            $res = $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => $view]))->assertOk()
                ->assertSee('data-qa="areas-empty"', false)->assertSee('Market Intelligence capture')->assertSee('P24 Auto-Import')
                ->assertSee(e(route('admin.minion.setup')), false);
            foreach (['Uvongo', 'Manaba', 'Ramsgate', 'Marina Beach'] as $name) {
                $res->assertDontSee($name);
            }
        }
        $this->assertSame([], app(AreaService::class)->poolQuery($this->agency->id)->pluck('s.id')->all(), 'no fallback to every suburb');
    }

    public function test_the_picker_and_the_town_filter_use_the_derived_pool(): void
    {
        $this->putInPool($this->agency->id, $this->sub['marina'], 2, 'p24', null, false);

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['add_for' => $this->agentNorth->id]))->assertOk()
            ->assertSee('Marina Beach')->assertSee('stock')->assertSee('MIC');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.areas.index', ['view' => 'suburbs', 'town_id' => $this->cityPort]))->assertOk()
            ->assertSee('Marina Beach')->assertDontSee('Uvongo');
    }
}
