<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\PermissionService;
use App\Support\Navigation\Hubs\ComplianceAgentsTabs;
use App\Support\Navigation\Hubs\ComplianceReportsTabs;
use App\Support\Navigation\Hubs\RmcpPoliciesTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/compliance-menu-tabs.md - the Compliance menu went from 13 links to 5 items: FICA and Send Standalone Info Pack stay plain
 * links; RMCP & Policies, Agents & Staff and Reports & Logs each open a screen with tabs. Each tab is the existing screen on its existing
 * route; a tab is visible exactly when the link it replaced was visible. Same mechanism as the other *MenuTabsTest files.
 */
final class ComplianceMenuTabsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

    private const HUBS = [RmcpPoliciesTabs::class, ComplianceAgentsTabs::class, ComplianceReportsTabs::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $now = now();
        foreach ([['super_admin', 'System Owner', 1], ['admin', 'Administrator', 0], ['branch_manager', 'Branch Manager', 0], ['agent', 'Agent', 0], ['viewer', 'Viewer', 0], ['office_admin', 'Office Staff', 0]] as $i => [$name, $label, $owner]) {
            DB::table('roles')->insertOrIgnore(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => $owner ? 0 : 1, 'sort_order' => $i + 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
        $this->agency = Agency::create(['name' => 'Second Agency ' . uniqid(), 'slug' => 'second-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** A same-agency user on a default role with its seeded grants ($keys === null) or exactly $keys (+ the sidebar section switches). */
    private function userWith(?array $keys, string $role): User
    {
        if ($keys !== null) {
            DB::table('role_permissions')->where('role', $role)->where('agency_id', $this->agency->id)->where('permission_key', 'not like', 'sidebar.%')->delete();
            foreach ($keys as $key) {
                RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
            }
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }

    /**
     * The gate of each OLD Compliance link, copied from the sidebar before the change (the panel sits inside @permission('access_compliance')
     * and @feature('compliance')).
     *
     * @return array<class-string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $panel = fn (User $u) => $u->hasFeature('compliance') && $u->hasPermission('access_compliance');
        $p = fn (User $u, string ...$keys) => $panel($u) && array_reduce($keys, fn ($ok, $k) => $ok && $u->hasPermission($k), true);

        return [
            RmcpPoliciesTabs::class => [
                'rmcp' => fn (User $u) => $p($u, 'access_rmcp'),
                'rmcp-dashboard' => fn (User $u) => $p($u, 'access_compliance_dashboard'),
                'policies' => fn (User $u) => $p($u, 'access_policy'),
                'policy-register' => fn (User $u) => $p($u, 'access_compliance_dashboard'),
            ],
            ComplianceAgentsTabs::class => [
                'verification' => fn (User $u) => $p($u, 'verify_user_documents'),
                'agents' => fn (User $u) => $panel($u) && ($u->isOwnerRole() || $u->effectiveRole() === 'super_admin'),
                'screening' => fn (User $u) => $p($u, 'manage_employee_screenings', 'access_compliance_dashboard'),
                'document-types' => fn (User $u) => $p($u, 'manage_agency_compliance'),
                'agency-documents' => fn (User $u) => $p($u, 'manage_agency_compliance'),
            ],
            ComplianceReportsTabs::class => [
                'reporting' => fn (User $u) => $p($u, 'compliance.whistleblow.view'),
                'communications-log' => fn (User $u) => $p($u, 'access_communication_archive'),
                'desk-sessions' => fn (User $u) => $p($u, 'manage_users'), // CoreX Desk audit (an agency admin; spec corex-desk.md)
            ],
        ];
    }

    private function allKeysAdmin(): User
    {
        $admin = $this->userWith(null, 'admin');
        $keys = ['access_compliance', 'access_my_portal', 'view_dashboard', 'outreach.compose'];
        foreach (self::HUBS as $class) {
            foreach ($class::tabs() as $def) {
                $keys = array_merge($keys, $def['permissions']);
            }
        }
        foreach (array_unique($keys) as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        return $admin;
    }

    public function test_every_tab_is_visible_exactly_when_its_old_link_was(): void
    {
        $users = [
            'agent defaults' => $this->userWith(null, 'agent'),
            'branch manager defaults' => $this->userWith(null, 'branch_manager'),
            'office staff defaults' => $this->userWith(null, 'office_admin'),
            'admin defaults' => $this->userWith(null, 'admin'),
            'policies without the panel key' => $this->userWith(['access_policy', 'verify_user_documents'], 'viewer'),
        ];
        $checked = 0;
        foreach ($users as $who => $user) {
            PermissionService::clearCache();
            $this->actingAs($user); // a feature switch is read for the signed-in user's agency, as the menu does
            foreach ($this->oldMenuGates() as $class => $gates) {
                $this->assertSame(array_keys($gates), array_keys($class::tabs()), "{$class}: the tab list and the old links line up");
                $visible = $class::forUser($user);
                foreach ($gates as $tab => $gate) {
                    $this->assertSame($gate($user), isset($visible[$tab]), "{$who}: {$class} > {$tab} visible exactly as its old link was");
                    $checked++;
                }
            }
        }
        $this->assertSame(5 * 12, $checked);
    }

    public function test_the_menu_has_five_items_and_every_tab_screen_opens_with_its_tab_marked(): void
    {
        $admin = $this->allKeysAdmin();
        $this->actingAs($admin);

        $html = $this->get(route('compliance.fica.index'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $items = [];
        foreach ((new \DOMXPath($dom))->query('//div[contains(@class,"corex-nav-panel") and contains(@*[name()=":class"], "inStack(\'compliance\')")]/a[contains(@class,"corex-nav-subitem")]') as $a) {
            $items[] = trim(preg_replace('/\s+\d+$/', '', trim(preg_replace('/\s+/', ' ', $a->textContent))));
        }
        $this->assertSame(['FICA', 'RMCP & Policies', 'Agents & Staff', 'Reports & Logs', 'Send Standalone Info Pack'], $items);

        $this->get(route('corex.hubs.rmcp-policies'))->assertRedirect(route('compliance.rmcp.index'));

        $opened = 0;
        foreach (self::HUBS as $class) {
            foreach ($class::forUser($admin) as $tab => $t) {
                $page = $this->get($t['url'])->assertOk()->getContent();
                $this->assertStringContainsString('data-hub-tabs aria-label="' . e($class::label()) . '"', $page, "{$tab}: the {$class::label()} bar");
                $this->assertMatchesRegularExpression('/data-hub-tab="' . $tab . '"[^>]*aria-current="page"/', $page, "{$tab}: its own tab is marked");
                $opened++;
            }
        }
        $this->assertSame(11, $opened, 'every tab an agency admin sees (Agent Compliance is System-Owner-only)');
    }

    public function test_the_counts_move_onto_their_tabs_and_the_menu_item(): void
    {
        $admin = $this->allKeysAdmin();
        $this->actingAs($admin);

        $this->assertSame(UserDocument::pending()->count(), ComplianceAgentsTabs::forUser($admin)['verification']['badge'], 'the Verification Queue tab carries the pending count');
        $this->assertTrue(ComplianceAgentsTabs::dotOnly('agents'));
        $this->assertFalse(ComplianceAgentsTabs::dotOnly('verification'));
        // a dot-only tab never adds to the number on the menu item
        $tabs = ['verification' => ['badge' => 3, 'dot' => false], 'agents' => ['badge' => 7, 'dot' => true]];
        $this->assertSame(3, ComplianceAgentsTabs::badgeTotal($tabs));
        $this->assertTrue(ComplianceAgentsTabs::hasDot($tabs));
    }
}
