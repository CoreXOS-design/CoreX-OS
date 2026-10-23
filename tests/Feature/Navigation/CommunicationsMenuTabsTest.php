<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Navigation\Hubs\CommsSetupTabs;
use App\Support\Navigation\Hubs\MessagesTabs;
use App\Support\Navigation\Hubs\WhatsAppTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/communications-menu-tabs.md - the Communications menu lost its five headings: twelve links became three items (Messages,
 * WhatsApp, Setup), each opening a screen with tabs. Each tab is the existing screen on its existing route; a tab is visible exactly
 * when the link it replaced was visible. Same mechanism as RealEstateMenuTabsTest / RentalsMenuTabsTest.
 */
final class CommunicationsMenuTabsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

    private const HUBS = [MessagesTabs::class, WhatsAppTabs::class, CommsSetupTabs::class];

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
     * The gate of each OLD Communications link, copied from the sidebar before the change (inside @feature('communications'); the
     * $canSeeCommunication / $canCapture / $canWa wrappers are each implied by the link's own permission, so they add nothing).
     *
     * @return array<class-string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $f = fn (User $u) => $u->hasFeature('communications');
        $p = fn (User $u, string ...$keys) => array_reduce($keys, fn ($ok, $k) => $ok && $u->hasPermission($k), true);

        return [
            MessagesTabs::class => [
                'incoming' => fn (User $u) => $f($u) && $p($u, 'triage_communications'),
                'archive' => fn (User $u) => $f($u) && $p($u, 'access_communication_archive'),
                'flagged' => fn (User $u) => $f($u) && $p($u, 'view_communication_flag_register'),
                'access-requests' => fn (User $u) => $f($u) && $p($u, 'communications.view'),
                'to-file' => fn (User $u) => $f($u) && $p($u, 'deal_comms_suspense.view'),
            ],
            WhatsAppTabs::class => [
                'my-consent' => fn (User $u) => $f($u) && $p($u, 'access_communication'),
                'consent-review' => fn (User $u) => $f($u) && $p($u, 'communications.capture_review', 'access_communication'),
                'extension' => fn (User $u) => $f($u) && $p($u, 'access_communication'),
                'link' => fn (User $u) => $f($u) && $p($u, 'access_communication', 'access_my_portal'),
            ],
            CommsSetupTabs::class => [
                'my-setup' => fn (User $u) => $f($u) && $p($u, 'access_communication'),
                'mailboxes' => fn (User $u) => $f($u) && $p($u, 'manage_communication_mailboxes'),
                'email-setup' => fn (User $u) => $f($u) && $p($u, 'manage_communication_mailboxes'),
            ],
        ];
    }

    private function allKeysAdmin(): User
    {
        $admin = $this->userWith(null, 'admin');
        $keys = ['access_my_portal', 'view_dashboard'];
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
            'archive only' => $this->userWith(['access_communication_archive', 'communications.capture_review'], 'viewer'),
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

    public function test_the_menu_has_three_items_no_headings_and_lands_on_the_first_tab(): void
    {
        $admin = $this->allKeysAdmin();
        $html = $this->actingAs($admin)->get(route('compliance.comm-archive.index'))->assertOk()->getContent();

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $panel = '//div[contains(@class,"corex-nav-panel")][div[contains(@class,"corex-nav-panel-title") and normalize-space(.)="Communications"]]';
        $items = [];
        foreach ((new \DOMXPath($dom))->query($panel . '/a[contains(@class,"corex-nav-subitem")]') as $a) {
            $items[] = trim($a->textContent);
        }
        $this->assertSame(['Messages', 'WhatsApp', 'Setup'], $items);
        $this->assertSame(0, (new \DOMXPath($dom))->query($panel . '/div[@style]')->length, 'no section headings left');

        $this->actingAs($admin)->get(route('corex.hubs.messages'))->assertRedirect(route('communications.triage.index'));
        $this->actingAs($admin)->get(route('corex.hubs.whatsapp'))->assertRedirect(route('communications.capture.my'));
        $this->actingAs($admin)->get(route('corex.hubs.comms-setup'))->assertRedirect(route('my-portal.comm-capture.index'));
        // a user who may open only the archive lands on it
        $archiveOnly = $this->userWith(['access_communication_archive'], 'viewer');
        $this->actingAs($archiveOnly)->get(route('corex.hubs.messages'))->assertRedirect(route('compliance.comm-archive.index'));
    }

    public function test_every_tab_screen_opens_with_its_bar_and_its_own_tab_marked(): void
    {
        $admin = $this->allKeysAdmin();
        foreach (self::HUBS as $class) {
            foreach ($class::tabs() as $tab => $def) {
                if (($def['match'] ?? null) === []) {
                    continue; // Link My WhatsApp jumps to My Portal > Tools - checked below
                }
                $html = $this->actingAs($admin)->get(route($def['route'], $def['query'] ?? []))->assertOk()->getContent();
                $this->assertStringContainsString('data-hub-tabs aria-label="' . $class::label() . '"', $html, "{$tab}: the {$class::label()} bar");
                $this->assertMatchesRegularExpression('/data-hub-tab="' . $tab . '"\s+aria-current="page"/', $html, "{$tab}: its own tab is marked");
            }
        }

        // Link My WhatsApp: a tab that goes to My Portal > Tools, exactly where the old link went; My Portal itself shows no WhatsApp bar
        $html = $this->actingAs($admin)->get(route('communications.capture.my'))->getContent();
        $this->assertStringContainsString('href="' . route('agent.portal') . '#tools" data-hub-tab="link"', $html);
        $this->assertStringNotContainsString('data-hub-tabs aria-label="WhatsApp"', $this->actingAs($admin)->get(route('agent.portal'))->getContent());
    }
}
