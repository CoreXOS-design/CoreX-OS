<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Company Settings → "Other Agency Stock" tab (.ai/specs/other-agency-stock.md §6/§3a, "Where to find it").
 *
 * Johan could not find the two Other Agency Stock settings under Company Settings: the card lived at the
 * bottom of the Branches tab. It is now its own, plainly named tab, saved by the same
 * SettingsController::updateOtherAgencyStock the Setup Wizard's Properties step uses.
 */
final class CompanySettingsOtherAgencyStockTabTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function agency(string $name = 'Cape Town Rentals'): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => 'oas-tab-' . uniqid()]);
        foreach (['admin' => 'Administrator', 'branch_manager' => 'Branch Manager', 'agent' => 'Agent'] as $role => $label) {
            Role::create(['name' => $role, 'label' => $label, 'agency_id' => $agency->id]);
        }
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_performance_settings', 'agency_id' => $agency->id],
            []
        );
        Role::clearCache();
        PermissionService::clearCache();

        return $agency;
    }

    private function userFor(Agency $agency, string $role): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main ' . uniqid()]);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    public function test_company_settings_has_a_tab_named_other_agency_stock_with_both_settings(): void
    {
        $agency = $this->agency();
        $admin  = $this->userFor($agency, 'admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.company-settings'))
            ->assertOk()
            ->getContent();

        // The tab button is in the tab bar, and the URL fragment can open it.
        $this->assertStringContainsString("activeTab = 'other-agency-stock'", $html);
        $this->assertStringContainsString("'branches', 'other-agency-stock'", $html);
        $this->assertMatchesRegularExpression('/>\s*Other Agency Stock\s*<\/button>/', $html);

        // Both settings are inside the new tab's own panel — not inside Branches.
        $branches = strpos($html, "x-show=\"activeTab === 'branches'\"");
        $tab      = strpos($html, "x-show=\"activeTab === 'other-agency-stock'\"");
        $perf     = strpos($html, "x-show=\"activeTab === 'performance'\"");
        $roles    = strpos($html, 'Who can see Other Agency Stock');
        $wording  = strpos($html, 'Import consent wording');
        $this->assertNotFalse($branches);
        $this->assertNotFalse($tab);
        $this->assertNotFalse($perf);
        $this->assertGreaterThan($branches, $tab, 'the new tab panel follows the Branches panel');
        $this->assertGreaterThan($tab, $roles, 'the role tick-list is inside the Other Agency Stock panel');
        $this->assertGreaterThan($tab, $wording, 'the consent wording is inside the Other Agency Stock panel');
        $this->assertLessThan($perf, $roles);
        $this->assertLessThan($perf, $wording);

        // Every role of the agency is offered, all ticked (null = visible to everyone, the default).
        foreach (['admin', 'branch_manager', 'agent'] as $role) {
            $this->assertMatchesRegularExpression(
                '/name="other_agency_stock_visible_roles\[\]" value="' . $role . '"\s*checked/',
                $html
            );
        }
        // Default wording is a placeholder, never pre-filled.
        $this->assertStringContainsString('placeholder="', $html);
    }

    public function test_save_from_the_tab_stores_both_settings_and_lands_back_on_the_tab(): void
    {
        $agency = $this->agency();
        $admin  = $this->userFor($agency, 'admin');

        $resp = $this->actingAs($admin)
            ->from(route('admin.company-settings'))
            ->put(route('corex.settings.other-agency-stock'), [
                'return_fragment'                    => 'other-agency-stock',
                'other_agency_stock_visible_roles_submitted' => '1',
                'other_agency_stock_visible_roles'   => ['admin', 'branch_manager'],
                'other_agency_stock_consent_wording' => '  I confirm I may use this listing.  ',
            ]);

        $resp->assertRedirect();
        $this->assertStringEndsWith('#other-agency-stock', $resp->headers->get('Location'));
        $resp->assertSessionHas('success', 'Other Agency Stock settings updated.');

        $agency->refresh();
        $this->assertSame(['admin', 'branch_manager'], $agency->other_agency_stock_visible_roles);
        $this->assertSame('I confirm I may use this listing.', $agency->other_agency_stock_consent_wording);

        // Reopen the tab: Agent is unticked, the wording is shown back.
        $html = $this->actingAs($admin)->get(route('admin.company-settings'))->getContent();
        $this->assertMatchesRegularExpression('/value="admin"\s*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="agent"\s*checked/', $html);
        $this->assertStringContainsString('I confirm I may use this listing.', $html);
    }

    public function test_every_role_ticked_and_blank_wording_go_back_to_the_defaults(): void
    {
        $agency = $this->agency();
        $agency->update([
            'other_agency_stock_visible_roles'   => ['admin'],
            'other_agency_stock_consent_wording' => 'Old wording',
        ]);
        $admin = $this->userFor($agency, 'admin');

        $this->actingAs($admin)->put(route('corex.settings.other-agency-stock'), [
            'return_fragment'                    => 'other-agency-stock',
            'other_agency_stock_visible_roles_submitted' => '1',
            'other_agency_stock_visible_roles'   => ['admin', 'branch_manager', 'agent'],
            'other_agency_stock_consent_wording' => '   ',
        ])->assertRedirect();

        $agency->refresh();
        $this->assertNull($agency->other_agency_stock_visible_roles);
        $this->assertNull($agency->other_agency_stock_consent_wording);
    }

    public function test_the_wizard_style_post_gets_no_fragment_and_an_unknown_fragment_is_ignored(): void
    {
        $agency = $this->agency();
        $admin  = $this->userFor($agency, 'admin');
        $payload = [
            'other_agency_stock_visible_roles_submitted' => '1',
            'other_agency_stock_visible_roles'   => ['admin'],
        ];

        $plain = $this->actingAs($admin)->from('/corex/agency-setup/properties')
            ->put(route('corex.settings.other-agency-stock'), $payload);
        $this->assertStringNotContainsString('#', $plain->headers->get('Location'));

        $bogus = $this->actingAs($admin)->from('/corex/agency-setup/properties')
            ->put(route('corex.settings.other-agency-stock'), $payload + ['return_fragment' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('#', $bogus->headers->get('Location'));
        $this->assertStringNotContainsString('javascript', $bogus->headers->get('Location'));
    }

    public function test_a_post_that_never_showed_a_field_leaves_that_field_alone(): void
    {
        $agency = $this->agency();
        $agency->update(['other_agency_stock_visible_roles' => ['admin'], 'other_agency_stock_consent_wording' => 'Keep me']);
        $admin = $this->userFor($agency, 'admin');

        $this->actingAs($admin)->put(route('corex.settings.other-agency-stock'), [
            'return_fragment' => 'other-agency-stock',
        ])->assertRedirect();

        $agency->refresh();
        $this->assertSame(['admin'], $agency->other_agency_stock_visible_roles);
        $this->assertSame('Keep me', $agency->other_agency_stock_consent_wording);
    }

    public function test_another_agency_is_never_touched(): void
    {
        $mine  = $this->agency('Mine');
        $other = $this->agency('Other');
        $other->update(['other_agency_stock_visible_roles' => ['agent'], 'other_agency_stock_consent_wording' => 'Theirs']);
        $admin = $this->userFor($mine, 'admin');

        $this->actingAs($admin)->put(route('corex.settings.other-agency-stock'), [
            'return_fragment' => 'other-agency-stock',
            'other_agency_stock_visible_roles_submitted' => '1',
            'other_agency_stock_visible_roles' => ['admin'],
            'other_agency_stock_consent_wording' => 'Mine only',
        ])->assertRedirect();

        $other->refresh();
        $this->assertSame(['agent'], $other->other_agency_stock_visible_roles);
        $this->assertSame('Theirs', $other->other_agency_stock_consent_wording);
        $this->assertSame('Mine only', $mine->fresh()->other_agency_stock_consent_wording);
    }

    public function test_a_user_without_the_settings_permission_can_neither_open_nor_save(): void
    {
        $agency = $this->agency();
        $agent  = $this->userFor($agency, 'agent');

        $this->actingAs($agent)->get(route('admin.company-settings'))->assertForbidden();
        $this->actingAs($agent)->put(route('corex.settings.other-agency-stock'), [
            'other_agency_stock_consent_wording' => 'Nope',
        ])->assertForbidden();

        $this->assertNull($agency->fresh()->other_agency_stock_consent_wording);
    }

    public function test_the_settings_search_finds_other_agency_stock_and_opens_the_tab(): void
    {
        $agency = $this->agency();
        // Both roles may open the Settings hub; only admin holds manage_performance_settings.
        foreach (['admin', 'agent'] as $role) {
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission_key' => 'access_settings', 'agency_id' => $agency->id],
                []
            );
        }
        PermissionService::clearCache();
        $admin = $this->userFor($agency, 'admin');

        $html = $this->actingAs($admin)->get(route('corex.settings'))->assertOk()->getContent();
        $this->assertStringContainsString('/admin/company-settings#other-agency-stock', $html);
        $this->assertStringContainsString('<span>Other Agency Stock</span>', $html);

        $agent = $this->userFor($agency, 'agent');
        $htmlAgent = $this->actingAs($agent)->get(route('corex.settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString('/admin/company-settings#other-agency-stock', $htmlAgent);
    }
}
