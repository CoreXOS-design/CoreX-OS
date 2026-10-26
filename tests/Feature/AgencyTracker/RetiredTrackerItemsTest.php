<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\Activity\ActivityPointsService;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md steps 5, 9 and 11 (AT-454 lane, Johan via the conductor, 26 Oct 2026).
 *
 * Step 5: the Worksheet leaves the menu; GET and POST /worksheet 301 to My Portal > Targets & Budget; nothing is saved.
 * Step 9: Branch Listing Stock and RCR · FIC 2026 leave the menu; /bm/listings 301s to Properties (own branch), the RCR list to the FICA centre.
 * Step 11: the empty headings "My Performance", "Branch" and "Admin" are gone; the Agency Tracker panel ends with Commission only. An agency whose
 * daily activity has NOT moved to Road to Sold keeps its Daily Activities items until it does; a user left with nothing gets no empty group.
 */
final class RetiredTrackerItemsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        ActivityPointsService::forgetCutovers();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        foreach (['admin', 'agent'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }
        $ownerRole = Role::create(['name' => 'owner', 'label' => 'Owner', 'agency_id' => $this->agency->id]);
        // is_owner is guarded against mass assignment, so the fixture forces it.
        $ownerRole->forceFill(['is_owner' => true, 'can_be_deleted' => false])->save();

        foreach (['agent' => 'agent', 'admin' => 'admin', 'owner' => 'owner'] as $prop => $role) {
            $this->{$prop} = User::factory()->create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
            ]);
        }

        $everyone = ['access_my_portal', 'access_agency_tracker', 'view_own_stats', 'view_daily_activity', 'view_worksheet', 'sidebar.section.agents'];
        $managers = ['view_branch_stats', 'view_company_stats', 'access_listing_stock', 'manage_targets', 'sidebar.section.admin'];
        foreach (['agent' => $everyone, 'admin' => array_merge($everyone, $managers), 'owner' => array_merge($everyone, $managers)] as $role => $keys) {
            foreach ($keys as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id]);
            }
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        $this->feature('commission-management', true);
    }

    protected function tearDown(): void
    {
        ActivityPointsService::forgetCutovers();
        parent::tearDown();
    }

    private function feature(string $key, bool $on): void
    {
        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => $key], ['enabled' => $on, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    private function moveToRoadToSold(): void
    {
        DB::table('rts_cutovers')->insert(['agency_id' => $this->agency->id, 'track' => 'sales', 'cut_over_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        ActivityPointsService::forgetCutovers();
    }

    /** @return array{items: string[], labels: string[]}|null null when the Agency Tracker panel is not drawn at all */
    private function trackerPanel(User $u): ?array
    {
        $html = $this->actingAs($u)->get(route('agent.portal'))->assertOk()->getContent();
        $parts = explode('<div class="corex-nav-panel-title">Agency Tracker</div>', $html, 2);
        if (count($parts) < 2) {
            return null;
        }
        $next = strpos($parts[1], 'corex-nav-panel-title">');
        $seg = $next === false ? $parts[1] : substr($parts[1], 0, $next);
        preg_match_all('~<a href="[^"]*" class="corex-nav-subitem[^"]*"[^>]*>\s*(?:<span>)?\s*([^<]+?)\s*(?:</span>|</a>)~', $seg, $items);
        preg_match_all('~corex-nav-sublabel">([^<]+)<~', $seg, $labels);

        return ['items' => array_map(fn ($v) => trim(html_entity_decode($v)), $items[1]), 'labels' => array_map('trim', $labels[1])];
    }

    // ── redirects ──

    public function test_the_worksheet_address_301s_to_targets_and_budget_and_saves_nothing(): void
    {
        $ws = Worksheet::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'period' => now()->format('Y-m'),
            'personal_net_target' => 40000, 'business_net_target' => 20000,
        ]);

        $this->actingAs($this->agent)->get('/worksheet')->assertStatus(301)->assertRedirect(route('agent.portal', ['sub' => 'targets']));
        $this->actingAs($this->agent)->get('/worksheet?period=2026-08')->assertStatus(301)->assertRedirect(route('agent.portal', ['sub' => 'targets', 'period' => '2026-08']));
        $this->actingAs($this->agent)->post('/worksheet', ['personal_net_target' => 1, 'business_net_target' => 1])
            ->assertStatus(301)->assertRedirect(route('agent.portal', ['sub' => 'targets']));

        $this->assertSame(40000, (int) $ws->fresh()->personal_net_target, 'a stale POST saves nothing');
        $this->assertSame(20000, (int) $ws->fresh()->business_net_target);
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('worksheet.index') && \Illuminate\Support\Facades\Route::has('worksheet.store'), 'route names kept');
    }

    public function test_branch_listing_stock_301s_to_the_properties_list_for_the_managers_branch(): void
    {
        $this->actingAs($this->admin)->get('/bm/listings')->assertStatus(301)
            ->assertRedirect(route('corex.properties.index', ['scope' => 'branch', 'branch_id' => $this->branch->id]));
        $this->actingAs($this->admin)->get('/bm/listings?filter=active')->assertStatus(301)
            ->assertRedirect(route('corex.properties.index', ['scope' => 'branch', 'branch_id' => $this->branch->id, 'status' => 'active']));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('bm.listings'), 'route name kept');
    }

    public function test_rcr_list_301s_to_the_fica_centre_and_its_data_is_untouched(): void
    {
        $this->actingAs($this->admin)->get('/corex/compliance/rcr')->assertStatus(301)->assertRedirect(route('compliance.fica.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('corex.compliance.rcr.show'), 'the submission pages are still registered');
    }

    // ── menu ──

    public function test_agency_not_yet_moved_keeps_daily_activities_and_loses_the_retired_items_and_empty_headings(): void
    {
        foreach (['agent' => $this->agent, 'admin' => $this->admin, 'owner' => $this->owner] as $who => $user) {
            $panel = $this->trackerPanel($user);
            $this->assertNotNull($panel, "{$who} still has Agency Tracker (Daily Activities not moved)");
            foreach (['Worksheet', 'Branch Listing Stock', 'RCR · FIC 2026'] as $gone) {
                $this->assertNotContains($gone, $panel['items'], "{$who}: no '{$gone}' item");
            }
            foreach (['My Performance', 'Branch', 'Admin'] as $gone) {
                $this->assertNotContains($gone, $panel['labels'], "{$who}: no empty '{$gone}' heading");
            }
            $this->assertContains('Daily Activity Summary', $panel['items'], "{$who}: Daily Activity Summary kept until the agency moves");
            $this->assertContains('Daily Activities', $panel['labels']);
        }
    }

    public function test_commission_items_are_still_there_for_the_owner_and_only_the_owner(): void
    {
        $owner = $this->trackerPanel($this->owner);
        $this->assertContains('Commission Overview', $owner['items']);
        $this->assertContains('Commission Management', $owner['items']);
        $this->assertContains('Commission', $owner['labels']);

        foreach (['agent' => $this->agent, 'admin' => $this->admin] as $who => $user) {
            $panel = $this->trackerPanel($user);
            $this->assertNotContains('Commission Overview', $panel['items'] ?? [], "{$who} never saw the commission items");
        }
    }

    public function test_once_the_agency_has_moved_the_panel_ends_with_commission_only_and_nobody_gets_an_empty_group(): void
    {
        $this->moveToRoadToSold();

        $owner = $this->trackerPanel($this->owner);
        $this->assertSame(['Commission Overview', 'Commission Management'], $owner['items']);
        $this->assertSame(['Commission'], $owner['labels']);

        $this->assertNull($this->trackerPanel($this->agent), 'an agent with nothing left sees no Agency Tracker entry');
        $this->assertNull($this->trackerPanel($this->admin), 'an admin with nothing left sees no Agency Tracker entry');
    }

    public function test_the_sidebar_source_has_no_link_to_the_retired_routes(): void
    {
        $src = (string) file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        foreach (["route('worksheet.index')", "route('bm.listings')", "route('corex.compliance.rcr.index')"] as $needle) {
            $this->assertStringNotContainsString($needle, $src);
        }
    }
}
