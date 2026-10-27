<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\EmptyListExplainer;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\Feature\RoadToSold\Concerns\SeedsDerivedAreas;
use Tests\TestCase;

/**
 * Road to Sold - an empty day says WHY and gives the one next action (spec section 10.5, "Empty states"), and a manager can build
 * an agent's list from the empty page with the SAME builder the overnight job uses.
 *
 * Reasons proven: not a working day, every source off, locked to areas with none, not built yet (someone else's page), nothing matches
 * (the build ran), build failed. Each has its sentence, its single action, and the action only when the viewer may use it.
 */
final class EmptyListReasonTest extends TestCase
{
    use RefreshDatabase, RtsFixtures, SeedsDerivedAreas;

    private const TUE = '2026-10-13 09:00:00';
    private const SAT = '2026-10-17 09:00:00';

    private User $manager;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow(self::TUE);
        $this->setUpAgency();

        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);

        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $this->outsider = $this->makeAgent($other, $otherBranch);

        // A populated grants table (an empty one permits everything in tests): agents hold the page key + own breadth; managers branch breadth + the two fixes.
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        foreach (['road_to_sold.manage_areas', 'road_to_sold.manage_targets', 'road_to_sold.manage_settings'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function setting(array $attrs): void
    {
        RtsSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], $attrs);
        SettingsService::forget();
    }

    private function page(User $as, array $query = [])
    {
        return $this->actingAs($as)->get(route('road-to-sold.index', $query))->assertOk();
    }

    private function listRows(User $u): int
    {
        return RtsListItem::withoutGlobalScopes()->where('user_id', $u->id)->where('list_date', '2026-10-13')->count();
    }

    // ── the reasons ──────────────────────────────────────────────────────

    public function test_a_weekend_says_it_is_not_a_working_day_and_only_a_manager_is_offered_the_fix(): void
    {
        Carbon::setTestNow(self::SAT);

        $this->page($this->agent)->assertSee('No list for today.')
            ->assertSee('Today is not a working day for you: weekend, public holiday or leave.')
            ->assertDontSee('Change working days');

        $this->page($this->manager, ['agent' => $this->agent->id])
            ->assertSee('Today is not a working day for ' . $this->agent->name . ': weekend, public holiday or leave.')
            ->assertSee('Change working days')
            ->assertSee(route('road-to-sold.settings.targets.index'), false);
    }

    public function test_every_source_switched_off_says_so(): void
    {
        $off = array_map(fn ($k) => ['key' => $k, 'enabled' => false], array_keys(RtsSetting::selectionCatalogue()));
        $this->setting(['selections' => $off]);

        $this->page($this->agent)->assertSee('Every list source is switched off for your agency.')->assertDontSee('Switch sources on');
        $this->page($this->manager, ['agent' => $this->agent->id])->assertSee('Switch sources on');
    }

    public function test_locked_to_areas_with_none_points_the_manager_to_areas_and_tells_the_agent_to_ask(): void
    {
        $this->setting(['lock_agents_to_areas' => true]);

        $this->page($this->agent)
            ->assertSee('Your agency gives each agent a list from their own areas, and you have none set. Ask your manager to set them.')
            ->assertDontSee('Set your areas');

        $this->page($this->manager, ['agent' => $this->agent->id])
            ->assertSee($this->agent->name . ' has none set.')
            ->assertSee("Set " . explode(' ', $this->agent->name)[0] . "'s areas")
            ->assertSee(route('road-to-sold.settings.areas.index'), false);
    }

    public function test_the_agent_who_opens_their_own_page_gets_the_build_and_then_nothing_matches_is_said_plainly(): void
    {
        // No stock at all: the page tries the build when it opens, finds nothing, and says so - it does not claim "not built".
        $this->page($this->agent)->assertSee('No list for today.')
            ->assertSee("Nothing in your agency's market data matches the list rules today.")
            ->assertDontSee('Build my list');
        $this->assertSame(0, $this->listRows($this->agent));
    }

    public function test_a_manager_looking_at_an_unbuilt_list_gets_one_button_and_it_builds_that_list_with_the_real_builder(): void
    {
        $this->listing(['listed_date' => '2026-10-12']);
        $this->listing(['listed_date' => '2026-10-11']);

        // The manager's page does not build for the agent; it offers to.
        $this->page($this->manager, ['agent' => $this->agent->id])
            ->assertSee($this->agent->name . "'s list for today has not been built yet.")
            ->assertSee('Build ' . explode(' ', $this->agent->name)[0] . "'s list")
            ->assertSee(route('road-to-sold.build-list'), false);
        $this->assertSame(0, $this->listRows($this->agent));

        $res = $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id]);
        $res->assertRedirect(route('road-to-sold.index', ['agent' => $this->agent->id]));
        $this->assertGreaterThan(0, $this->listRows($this->agent));
        $this->assertSame(['auto'], RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->pluck('origin')->unique()->values()->all());

        // The same builder is idempotent: pressing it again adds nothing.
        $before = $this->listRows($this->agent);
        $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id]);
        $this->assertSame($before, $this->listRows($this->agent));

        // And the agent's page now shows the list, not the empty state.
        $this->page($this->agent)->assertDontSee('No list for today.');
    }

    public function test_a_build_that_finds_nothing_lands_on_nothing_matches_not_on_not_built(): void
    {
        $res = $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id]);
        $res->assertRedirect(route('road-to-sold.index', ['agent' => $this->agent->id]));

        $this->followingRedirects()->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id])
            ->assertSee("Nothing in your agency's market data matches the list rules today.")
            ->assertDontSee('has not been built yet');
    }

    public function test_a_build_that_throws_says_it_could_not_and_offers_to_try_again(): void
    {
        $this->mock(ListBuilderService::class, function ($m) {
            $m->shouldReceive('buildFor')->andThrow(new \RuntimeException('boom'));
        });

        $this->followingRedirects()->actingAs($this->agent)->get(route('road-to-sold.index'))
            ->assertSee('Your list could not be built just now.')
            ->assertSee('Try again')
            ->assertSee(route('road-to-sold.build-list'), false);
    }

    public function test_nothing_is_explained_for_a_day_that_is_not_today(): void
    {
        $this->page($this->agent, ['date' => '2026-10-06'])->assertSee('No list for 6 Oct.')
            ->assertDontSee('data-empty-reason', false);
        $this->assertNull(app(EmptyListExplainer::class)->explain($this->agent, $this->agent, Carbon::parse('2026-10-06'), 'sales', true, true));
    }

    public function test_a_day_with_rows_shows_no_empty_reason(): void
    {
        $this->listing(['listed_date' => '2026-10-12']);
        $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id]);

        $this->page($this->manager, ['agent' => $this->agent->id])->assertDontSee('data-empty-reason', false)->assertDontSee('No list for today.');
    }

    // ── who may press it ─────────────────────────────────────────────────

    public function test_the_build_button_cannot_be_used_on_an_agent_outside_the_viewers_breadth_or_agency(): void
    {
        // An agent (own breadth) cannot build a colleague's list; nobody builds another agency's.
        $this->actingAs($this->agent)->post(route('road-to-sold.build-list'), ['agent' => $this->mate->id])->assertNotFound();
        $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => $this->outsider->id])->assertNotFound();
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->whereIn('user_id', [$this->mate->id, $this->outsider->id])->count());
    }

    public function test_a_user_without_the_page_key_cannot_build(): void
    {
        $nobody = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer_none', 'is_active' => true]);
        $this->actingAs($nobody)->post(route('road-to-sold.build-list'), ['agent' => $this->agent->id])->assertForbidden();
    }

    public function test_the_agent_can_build_their_own_list_from_the_button(): void
    {
        $this->listing(['listed_date' => '2026-10-12']);
        $this->actingAs($this->agent)->post(route('road-to-sold.build-list'))->assertRedirect(route('road-to-sold.index'));
        $this->assertGreaterThan(0, $this->listRows($this->agent));
    }

    public function test_a_bad_agent_value_is_refused_and_writes_nothing(): void
    {
        $this->actingAs($this->manager)->post(route('road-to-sold.build-list'), ['agent' => 'abc'])->assertSessionHasErrors('agent');
        $this->assertSame(0, DB::table('rts_list_items')->count());
    }
}
