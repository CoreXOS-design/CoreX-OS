<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use App\Models\Role;
use App\Models\SoloAgentSignup;
use App\Models\User;
use App\Services\SoloAgent\SoloAgentProvisioner;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 5 - merged Settings + the single-agent sidebar:
 * .ai/specs/solo-agent-accounts.md sections 7.1 and 7.2. Normal agencies are unchanged.
 */
class SoloSettingsMergeTest extends TestCase
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
        parent::tearDown();
    }

    /** An open single-agent account's one user. */
    private function soloUser(string $email = 'solo@merge.test'): User
    {
        $r = app(SoloAgentProvisioner::class)->provision([
            'agency_name' => 'Merge Realty', 'name' => 'Sam Solo', 'email' => $email, 'cell' => '0820000000',
            'ffc_number' => 'F1', 'ffc_expires_on' => now()->addYear()->toDateString(),
        ]);
        app(SoloAgentService::class)->activate($r['signup']->fresh());

        return $r['user']->fresh();
    }

    private function normalAdmin(): User
    {
        $agency = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);

        return User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => 1]);
    }

    private function owner(): User
    {
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill(['label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false])->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => 1]);
    }

    // ── Company Settings no longer exists as a separate page for a single agent ─

    public function test_company_settings_redirects_a_single_agent_to_the_merged_settings(): void
    {
        $this->actingAs($this->soloUser())
            ->get(route('admin.company-settings'))
            ->assertRedirect(route('corex.settings', ['s' => 'agency']));
    }

    public function test_the_redirect_keeps_a_message_flashed_by_the_save_that_sent_them_there(): void
    {
        $this->actingAs($this->soloUser())
            ->withSession(['_flash' => ['old' => ['success'], 'new' => []], 'success' => 'Company settings saved.'])
            ->get(route('admin.company-settings'))
            ->assertRedirect();

        $this->assertContains('success', session('_flash.old', []), 'the save message must survive the extra redirect');
    }

    public function test_a_normal_agency_still_gets_the_full_company_settings_page(): void
    {
        $this->actingAs($this->normalAdmin())
            ->get(route('admin.company-settings'))
            ->assertOk()
            ->assertSee("activeTab === 'branches'", false)
            ->assertSee("activeTab === 'other-agency-stock'", false)
            ->assertSee('Company Identity');
    }

    public function test_the_system_owner_is_never_redirected_and_sees_every_tab_of_a_single_agents_page(): void
    {
        $solo = $this->soloUser();
        $this->actingAs($this->owner())
            ->get(route('admin.company-settings', ['agency' => $solo->agency_id]))
            ->assertOk()
            ->assertSee("activeTab === 'branches'", false);
    }

    // ── The merged Settings page ───────────────────────────────────────────

    public function test_the_single_agents_settings_page_carries_the_business_details_and_branding_forms(): void
    {
        $solo = $this->soloUser();

        $this->actingAs($solo)->get(route('corex.settings'))
            ->assertOk()
            ->assertSee('Business details &amp; branding', false)
            ->assertSee('Company Identity')
            ->assertSee('name="trading_name"', false)
            ->assertSee('name="reg_no"', false)
            ->assertSee('name="address"', false)
            ->assertSee("activeTab === 'branding'", false)
            ->assertSee("activeTab === 'performance'", false)
            // the very same saver the Company Settings page posts to
            ->assertSee(route('admin.company-settings.update', $solo->agency_id), false);
    }

    public function test_team_only_parts_do_not_appear_for_a_single_agent(): void
    {
        $this->actingAs($this->soloUser())->get(route('corex.settings'))
            ->assertOk()
            ->assertDontSee("activeTab === 'branches'", false)
            ->assertDontSee("activeTab === 'other-agency-stock'", false)
            ->assertDontSee("activeTab === 'compliance'", false)
            ->assertDontSee("activeTab === 'website'", false)
            ->assertDontSee(route('admin.branches.store'), false)
            ->assertDontSee('settings-rail-commission', false)
            ->assertDontSee('settings-rail-leave-visibility', false)
            ->assertDontSee('settings-rail-company', false)
            ->assertDontSee('Role &amp; Permissions Manager', false)
            ->assertDontSee('href="' . route('admin.company-settings') . '"', false);
    }

    public function test_a_normal_agencys_settings_page_is_unchanged(): void
    {
        $this->actingAs($this->normalAdmin())->get(route('corex.settings'))
            ->assertOk()
            ->assertSee('Agency Settings')
            ->assertSee('settings-rail-commission', false)
            ->assertSee('Role &amp; Permissions Manager', false)
            ->assertSee('href="' . route('admin.company-settings') . '"', false)
            ->assertDontSee('Business details &amp; branding', false)
            ->assertDontSee('name="trading_name"', false);
    }

    public function test_the_company_settings_saver_works_from_the_merged_page_and_lands_back_in_settings(): void
    {
        $solo = $this->soloUser();

        $this->actingAs($solo)
            ->put(route('admin.company-settings.update', $solo->agency_id), ['trading_name' => 'Sam Solo Property Co', 'reg_no' => '2025/111111/07'])
            ->assertRedirect(); // back to the (now merged) page

        $agency = Agency::find($solo->agency_id);
        $this->assertSame('Sam Solo Property Co', $agency->trading_name);
        $this->assertSame('2025/111111/07', $agency->reg_no);
        $this->assertTrue($agency->isSolo(), 'saving company details never changes the account kind');
    }

    public function test_an_old_bookmark_to_a_team_section_lands_on_the_business_details(): void
    {
        $this->actingAs($this->soloUser())->get(route('corex.settings', ['s' => 'commission']))
            ->assertOk()
            ->assertViewHas('activeSection', 'agency');
    }

    // ── The sidebar ────────────────────────────────────────────────────────

    public function test_the_single_agents_sidebar_has_one_settings_entry_and_no_team_entries(): void
    {
        $this->actingAs($this->soloUser())->get(route('corex.settings'))
            ->assertOk()
            ->assertSee('href="' . route('corex.settings') . '"', false)
            // sidebar entries are anchors with this class; the team pages have none for a single agent
            ->assertDontSee('<a href="' . route('corex.role-manager') . '" class="corex-nav-subitem', false)
            ->assertDontSee('<a href="' . route('admin.users') . '" class="corex-nav-subitem', false)
            ->assertDontSee('<a href="' . route('corex.features.index') . '" class="corex-nav-subitem', false)
            ->assertDontSee('<a href="' . route('corex.feature-rollout.index') . '" class="corex-nav-subitem', false)
            ->assertDontSee('<a href="' . route('admin.company-settings') . '" class="corex-nav-subitem', false);
    }

    public function test_a_normal_agencys_sidebar_keeps_its_team_entries(): void
    {
        $this->actingAs($this->normalAdmin())->get(route('corex.settings'))
            ->assertOk()
            ->assertSee('<a href="' . route('admin.company-settings') . '" class="corex-nav-subitem', false)
            ->assertSee('<a href="' . route('admin.users') . '" class="corex-nav-subitem', false);
    }

    public function test_the_team_pages_are_closed_to_a_single_agent_by_direct_url(): void
    {
        $this->actingAs($this->soloUser());

        $this->get(route('corex.role-manager'))->assertForbidden();
        $this->get(route('admin.users'))->assertForbidden();
        $this->get(route('corex.features.index'))->assertForbidden();
        $this->get(route('corex.feature-rollout.index'))->assertForbidden();
    }
}
