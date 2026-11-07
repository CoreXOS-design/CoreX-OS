<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\SoloAgent\SoloAgentProvisioner;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Solo Agent Accounts - P24 Auto-Import (the portal-intake capture page) is not part of a single-agent account (Johan, 2026-11-07).
 * Gone from the Settings list, from the Road to Sold empty-state links, and the page itself refuses a typed address.
 * A normal agency and the CoreX owner are unchanged.
 */
class SoloNoAutoImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function soloUser(): User
    {
        $r = app(SoloAgentProvisioner::class)->provision([
            'agency_name' => 'Quiet Realty', 'name' => 'Sam Solo', 'email' => 'solo@autoimport.test', 'cell' => '0820000000',
            'ffc_number' => 'F1', 'ffc_expires_on' => now()->addYear()->toDateString(),
        ]);
        app(SoloAgentService::class)->activate($r['signup']->fresh());

        return $r['user']->fresh();
    }

    private function normalAdmin(): User
    {
        $agency = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'scope' => null, 'agency_id' => $agency->id]);
        PermissionService::clearCache();

        return User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => 1]);
    }

    public function test_the_single_agents_settings_list_has_no_auto_import(): void
    {
        $solo = $this->soloUser();

        $this->actingAs($solo)->get(route('corex.settings'))
            ->assertOk()
            ->assertDontSee('P24 Auto-Import')
            ->assertDontSee(e(route('admin.minion.setup')), false);
    }

    public function test_the_auto_import_page_and_its_actions_refuse_a_single_agent(): void
    {
        $solo = $this->soloUser();
        $this->actingAs($solo);

        $this->get(route('admin.minion.setup'))->assertForbidden();
        $this->get(route('admin.minion.tree.towns'))->assertForbidden();
        $this->post(route('admin.minion.areas.toggle'))->assertForbidden();
        $this->post(route('admin.minion.settings.save'))->assertForbidden();
        $this->post(route('admin.minion.run-now'))->assertForbidden();
    }

    public function test_a_normal_agency_still_has_auto_import_in_settings_and_can_open_it(): void
    {
        $admin = $this->normalAdmin();

        $this->actingAs($admin)->get(route('corex.settings'))
            ->assertOk()
            ->assertSee('P24 Auto-Import')
            ->assertSee(e(route('admin.minion.setup')), false);

        $this->actingAs($admin)->get(route('admin.minion.setup'))->assertOk();
    }

    public function test_the_system_owner_is_never_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => 1]);
        $solo = $this->soloUser();

        $this->actingAs($owner)->get(route('admin.minion.setup', ['agency' => $solo->agency_id]))
            ->assertStatus(200);
    }

    public function test_road_to_sold_does_not_send_a_single_agent_to_auto_import(): void
    {
        $solo = $this->soloUser();

        foreach (['agents', 'suburbs'] as $view) {
            $this->actingAs($solo)->get(route('road-to-sold.settings.areas.index', ['view' => $view]))
                ->assertOk()
                ->assertDontSee('P24 Auto-Import')
                ->assertDontSee(e(route('admin.minion.setup')), false);
        }
    }
}
