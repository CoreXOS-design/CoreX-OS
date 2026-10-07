<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ListingStock;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Agent\AgentPerformanceService;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-10-07 — two Agency Tracker access bugs, proven against the real HTTP routes with TWO agencies.
 *
 *  1. Listing-stock routes (company stock, per-agent totals, agent drill-down, the reassign screens
 *     and /bm/listings) used to be gated only by view_listings, which every agent and viewer holds.
 *     An agent typing the URL saw agency-wide stock and could reassign listing agents.
 *       agent / viewer          -> 403 on every stock route
 *       branch manager          -> own branch's stock only; may reassign inside that branch
 *       admin                   -> the whole agency, never another agency
 *  2. The agent dashboard's Company / Branch tiles were summed across agencies.
 *
 * Roles resolve through PermissionService's test posture (no grants table => the role-shaped
 * defaults in config/corex-permissions.php: admin = all, branch_manager = branch, agent = own).
 */
final class TrackerStockAccessAndAgencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private Branch $b1;
    private Branch $b2;
    private Branch $fb;
    private User $admin;
    private User $bm;          // branch manager, B1
    private User $agent;       // agent, B1
    private User $colleague;   // agent, B2 (same agency, other branch)
    private User $viewer;      // viewer, B1
    private User $foreign;     // agent, other agency
    private User $foreignAdmin;
    private ListingStock $sB1;
    private ListingStock $sB2;
    private ListingStock $sForeign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->other  = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->fb = Branch::create(['agency_id' => $this->other->id, 'name' => 'Sea Point']);

        $mk = fn (string $name, string $role, Agency $a, Branch $b) => User::factory()->create([
            'name' => $name, 'role' => $role, 'agency_id' => $a->id, 'branch_id' => $b->id, 'is_active' => 1,
        ]);
        $this->admin        = $mk('Pam Principal', 'admin', $this->agency, $this->b1);
        $this->bm           = $mk('Bea Manager', 'branch_manager', $this->agency, $this->b1);
        $this->agent        = $mk('Alice Ramsgate', 'agent', $this->agency, $this->b1);
        $this->colleague    = $mk('Maggie Margate', 'agent', $this->agency, $this->b2);
        $this->viewer       = $mk('Vic Viewer', 'viewer', $this->agency, $this->b1);
        $this->foreign      = $mk('Frank Foreign', 'agent', $this->other, $this->fb);
        $this->foreignAdmin = $mk('Fiona Foreign', 'admin', $this->other, $this->fb);

        $this->seedRealGrants();

        $this->sB1      = $this->stock($this->agent, $this->b1, 'ZZRAMSGATE-HOUSE');
        $this->sB2      = $this->stock($this->colleague, $this->b2, 'ZZMARGATE-HOUSE');
        $this->sForeign = $this->stock($this->foreign, $this->fb, 'ZZSEAPOINT-HOUSE');
    }

    protected function tearDown(): void
    {
        \App\Services\PermissionService::clearCache();
        parent::tearDown();
    }

    /**
     * With an EMPTY grants table the test suite allows everything (AT-265 posture), which would make every
     * permission assertion vacuous. Seed the shipped role defaults for this agency so the 403s are real:
     * each role's include / all-minus-exclude set, with the `{module}.view` rows carrying scope_defaults.
     */
    private function seedRealGrants(): void
    {
        $cfg     = config('corex-permissions');
        $allKeys = array_column($cfg['permissions'], 'key');

        foreach ([$this->agency, $this->other] as $agency) {
            foreach (['admin', 'branch_manager', 'agent', 'viewer'] as $role) {
                foreach (RoleDefaultsResolver::keysForDef($cfg['role_defaults'][$role], $allKeys) as $key) {
                    RolePermission::updateOrCreate(
                        ['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id],
                        ['scope' => str_ends_with($key, '.view') ? ($cfg['scope_defaults'][$role] ?? null) : null],
                    );
                }
            }
        }

        // HFC's REAL grants (read off QA1): agents/viewers hold access_listing_stock and a listings + properties
        // scope of 'all', plus properties.edit. Mirror that, so the 403s below hold against the shape that
        // actually shipped, not just against the config defaults.
        foreach (['agent', 'viewer'] as $role) {
            foreach ([$this->agency, $this->other] as $agency) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'access_listing_stock', 'agency_id' => $agency->id], []);
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => 'properties.edit', 'agency_id' => $agency->id], []);
                foreach (['listings.view', 'properties.view'] as $key) {
                    RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id], ['scope' => 'all']);
                }
            }
        }
        PermissionService::clearCache();
    }

    private function stock(User $owner, Branch $branch, string $label): ListingStock
    {
        $m = new ListingStock();
        $m->forceFill([
            'agency_id' => $owner->agency_id, 'user_id' => $owner->id, 'branch_id' => $branch->id,
            'source' => 'propcon', 'property' => $label, 'status' => 'Active', 'price_cents' => 100000000,
            'listed_at' => now()->subDays(5), 'modified_at' => now()->subDays(2),
        ])->save();

        return $m;
    }

    private function stockRoutes(): array
    {
        return [
            'company stock'  => route('admin.listings.stock'),
            'agent totals'   => route('admin.listings.agents'),
            'agent drill'    => route('admin.listings.agents.show', ['user' => $this->agent->id]),
            'branch stock'   => route('bm.listings'),
            'reassign form'  => route('admin.listings.stock.agents.edit', ['listing' => $this->sB1->id]),
        ];
    }

    // ───────────────────── 1. agents and viewers are shut out ─────────────────────

    public function test_agent_and_viewer_get_403_on_every_listing_stock_route_including_reassign(): void
    {
        foreach ([$this->agent, $this->viewer] as $who) {
            $this->actingAs($who);

            foreach ($this->stockRoutes() as $label => $url) {
                $this->assertSame(403, $this->get($url)->getStatusCode(), "{$who->role} must not open {$label}");
            }

            $before = $this->sB1->fresh()->user_id;
            $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB1->id]), [
                'primary_user_id' => $this->colleague->id,
                'agent_ids' => [$this->colleague->id],
            ])->assertForbidden();
            $this->assertSame($before, $this->sB1->fresh()->user_id, 'a refused reassign must change nothing');
        }
    }

    public function test_even_with_the_manager_permission_a_narrow_properties_scope_cannot_reassign(): void
    {
        // Give the agent role the manager permission but narrow its scopes to 'own': it may read ONLY its own
        // stock, and the Properties module's gate (properties data scope all/branch) still blocks reassign.
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'view_branch_stats', 'agency_id' => $this->agency->id], []);
        foreach (['listings.view', 'properties.view'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();

        $this->actingAs($this->agent);

        $this->get(route('admin.listings.stock'))->assertOk()
            ->assertSee('ZZRAMSGATE-HOUSE')
            ->assertDontSee('ZZMARGATE-HOUSE')      // own scope: not even the branch
            ->assertDontSee('ZZSEAPOINT-HOUSE');

        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sB1->id]))->assertForbidden();
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB1->id]), [
            'primary_user_id' => $this->colleague->id,
        ])->assertForbidden();
        $this->assertSame($this->agent->id, $this->sB1->fresh()->user_id);
    }

    // ───────────────────── 2. branch manager: own branch only ─────────────────────

    public function test_branch_manager_sees_only_their_branch_stock_and_agents(): void
    {
        $this->actingAs($this->bm);

        $this->get(route('admin.listings.stock'))->assertOk()
            ->assertSee('ZZRAMSGATE-HOUSE')
            ->assertDontSee('ZZMARGATE-HOUSE')
            ->assertDontSee('ZZSEAPOINT-HOUSE');

        $this->get(route('bm.listings'))->assertOk()
            ->assertSee('ZZRAMSGATE-HOUSE')
            ->assertDontSee('ZZMARGATE-HOUSE')
            ->assertDontSee('ZZSEAPOINT-HOUSE');

        $totals = $this->get(route('admin.listings.agents'))->assertOk();
        // The app layout carries a user-switcher list, so compare inside <main> only.
        $main = substr($totals->getContent(), (int) strpos($totals->getContent(), '<main'));
        $this->assertStringContainsString('Alice Ramsgate', $main);
        $this->assertStringNotContainsString('Maggie Margate', $main);
        $this->assertStringNotContainsString('Frank Foreign', $main);

        $this->get(route('admin.listings.agents.show', ['user' => $this->agent->id]))->assertOk();
        $this->get(route('admin.listings.agents.show', ['user' => $this->colleague->id]))->assertNotFound();   // other branch
        $this->get(route('admin.listings.agents.show', ['user' => $this->foreign->id]))->assertNotFound();      // other agency
    }

    public function test_branch_manager_can_reassign_inside_their_branch_but_not_reach_other_branches_or_agencies(): void
    {
        $this->actingAs($this->bm);

        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sB1->id]))->assertOk();
        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sB2->id]))->assertNotFound();      // other branch
        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sForeign->id]))->assertNotFound(); // other agency

        // A foreign practitioner can never be assigned.
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB1->id]), [
            'primary_user_id' => $this->foreign->id,
            'agent_ids' => [$this->foreign->id],
        ])->assertStatus(422);
        $this->assertSame($this->agent->id, $this->sB1->fresh()->user_id);

        // Other branch / other agency rows cannot be written either.
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB2->id]), [
            'primary_user_id' => $this->agent->id,
        ])->assertNotFound();
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sForeign->id]), [
            'primary_user_id' => $this->agent->id,
        ])->assertNotFound();
        $this->assertSame($this->colleague->id, $this->sB2->fresh()->user_id);
        $this->assertSame($this->foreign->id, $this->sForeign->fresh()->user_id);

        // A same-agency assignee works.
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB1->id]), [
            'primary_user_id' => $this->bm->id,
            'agent_ids' => [$this->bm->id],
        ])->assertRedirect();
        $this->assertSame($this->bm->id, $this->sB1->fresh()->user_id);
    }

    // ───────────────────── 3. admin: whole agency, never another ─────────────────────

    public function test_admin_sees_the_whole_agency_and_nothing_from_another_agency(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.listings.stock'))->assertOk()
            ->assertSee('ZZRAMSGATE-HOUSE')
            ->assertSee('ZZMARGATE-HOUSE')
            ->assertDontSee('ZZSEAPOINT-HOUSE');

        $res = $this->get(route('admin.listings.agents'))->assertOk();
        $main = substr($res->getContent(), (int) strpos($res->getContent(), '<main'));
        $this->assertStringContainsString('Alice Ramsgate', $main);
        $this->assertStringContainsString('Maggie Margate', $main);
        $this->assertStringNotContainsString('Frank Foreign', $main);

        $this->get(route('admin.listings.agents.show', ['user' => $this->colleague->id]))->assertOk();
        $this->get(route('admin.listings.agents.show', ['user' => $this->foreign->id]))->assertNotFound();

        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sB2->id]))->assertOk();
        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sForeign->id]))->assertNotFound();

        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sForeign->id]), [
            'primary_user_id' => $this->agent->id,
        ])->assertNotFound();
        $this->post(route('admin.listings.stock.agents.update', ['listing' => $this->sB2->id]), [
            'primary_user_id' => $this->foreign->id,
        ])->assertStatus(422);
        $this->assertSame($this->colleague->id, $this->sB2->fresh()->user_id);
    }

    public function test_the_other_agencys_admin_sees_only_their_own_stock(): void
    {
        $this->actingAs($this->foreignAdmin);

        $this->get(route('admin.listings.stock'))->assertOk()
            ->assertSee('ZZSEAPOINT-HOUSE')
            ->assertDontSee('ZZRAMSGATE-HOUSE')
            ->assertDontSee('ZZMARGATE-HOUSE');

        $this->get(route('admin.listings.stock.agents.edit', ['listing' => $this->sB1->id]))->assertNotFound();
        $this->get(route('admin.listings.agents.show', ['user' => $this->agent->id]))->assertNotFound();
    }

    // ───────────────────── 4. agent dashboard Company / Branch tiles ─────────────────────

    private function targetFor(User $u, float $value, int $deals): void
    {
        DB::table('targets')->insert([
            'period' => Carbon::now()->format('Y-m'), 'user_id' => $u->id, 'agency_id' => $u->agency_id,
            'branch_id' => $u->branch_id, 'listings_target' => 0, 'deals_target' => $deals,
            'value_target' => $value, 'points_target' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_agent_dashboard_company_and_branch_tiles_never_include_another_agency(): void
    {
        // Coastal (this agency): Alice (B1) 1,000,000 / 1 deal, Maggie (B2) 2,000,000 / 2 deals.
        // Cape Rentals: Frank 7,000,000 / 7 deals — and Frank even sits in a branch with the SAME numeric id
        // as nothing of ours, so only an agency filter can keep him out of the Company tile.
        $this->targetFor($this->agent, 1_000_000, 1);
        $this->targetFor($this->colleague, 2_000_000, 2);
        $this->targetFor($this->foreign, 7_000_000, 7);

        $snap = app(AgentPerformanceService::class)->getMonthlySnapshot($this->agent, Carbon::now()->startOfMonth());

        $company = $snap['comparisons']['company'];
        $branch  = $snap['comparisons']['branch'];

        $this->assertEquals(3_000_000, $company['targets']['value'], 'Company tile = this agency only (1m + 2m), never + Cape Rentals 7m');
        $this->assertEquals(3, $company['targets']['deals']);
        $this->assertEquals(1_000_000, $branch['targets']['value'], 'Branch tile = own branch only');
        $this->assertEquals(1, $branch['targets']['deals']);

        // The other agency's agent sees THEIR agency only.
        $fsnap = app(AgentPerformanceService::class)->getMonthlySnapshot($this->foreign, Carbon::now()->startOfMonth());
        $this->assertEquals(7_000_000, $fsnap['comparisons']['company']['targets']['value']);
        $this->assertEquals(7_000_000, $fsnap['comparisons']['branch']['targets']['value']);
    }

    public function test_branch_and_agent_rollups_given_the_agency_never_count_another_agencys_points_today(): void
    {
        $defId = DB::table('activity_definitions')->insertGetId([
            'scope' => 'system', 'agency_id' => null, 'name' => 'Calls', 'weight' => 1, 'is_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = fn (User $u, int $value) => DB::table('daily_activity_entries')->insert([
            'activity_date' => now()->toDateString(), 'period' => now()->format('Y-m'), 'user_id' => $u->id,
            'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id, 'activity_definition_id' => $defId,
            'value' => $value, 'point_state' => 'confirmed', 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry($this->agent, 5);
        $entry($this->foreign, 90);

        $svc = app(\App\Services\Admin\CompanyPerformanceService::class);
        $period = now()->format('Y-m');

        $this->assertEquals(5.0, $svc->getBranchRollup($this->b1->id, $period, $this->agency->id)['points']['today_points']);
        $this->assertEquals(90.0, $svc->getBranchRollup($this->fb->id, $period, $this->other->id)['points']['today_points']);

        // The BM Performance page (which now hands the branch's agency to the rollup) still renders.
        $this->targetFor($this->agent, 1_000_000, 1);
        $this->actingAs($this->bm)->get(route('bm.performance'))->assertOk();
    }

    public function test_agent_dashboard_page_renders_for_each_agency(): void
    {
        $this->targetFor($this->agent, 1_000_000, 1);
        $this->targetFor($this->foreign, 7_000_000, 7);

        $this->actingAs($this->agent)->get(route('agent.dashboard'))->assertOk();
        $this->actingAs($this->foreign)->get(route('agent.dashboard'))->assertOk();
    }
}
