<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\CandidatePractitionerService;
use App\Services\PermissionService;
use App\Support\Navigation\Hubs\DocumentLibraryTabs;
use App\Support\Navigation\Hubs\DocumentTemplatesTabs;
use App\Support\Navigation\Hubs\ESignSettingsTabs;
use App\Support\Navigation\Hubs\MyDocumentsTabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/documents-menu-tabs.md - the Documents menu went from 20 links to 6 items: Create Document and E-Sign Document stay plain
 * links; My Documents, Library, Templates and E-Sign Settings each open a screen with tabs. Each tab is the existing screen on its
 * existing route; a tab is visible exactly when the link it replaced was visible. Same mechanism as the other *MenuTabsTest files.
 */
final class DocumentsMenuTabsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

    private const HUBS = [MyDocumentsTabs::class, DocumentLibraryTabs::class, DocumentTemplatesTabs::class, ESignSettingsTabs::class];

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
     * The gate of each OLD Documents link, copied from the sidebar before the change (the panel sits inside @feature('docuperfect')).
     *
     * @return array<class-string, array<string, callable(User): bool>>
     */
    private function oldMenuGates(): array
    {
        $dp = fn (User $u) => $u->hasFeature('docuperfect');
        $p = fn (User $u, string ...$keys) => array_reduce($keys, fn ($ok, $k) => $ok && $u->hasPermission($k), true);
        $manage = fn (User $u) => $dp($u) && $p($u, 'manage_templates', 'access_docuperfect');
        $esign = fn (User $u) => $dp($u) && $p($u, 'esign.settings');

        return [
            MyDocumentsTabs::class => [
                'documents' => fn (User $u) => $dp($u) && $p($u, 'access_docuperfect'),
                'esign' => fn (User $u) => $dp($u) && $p($u, 'create_docuperfect_docs', 'access_docuperfect'),
                'authorise' => fn (User $u) => $dp($u) && $p($u, 'create_docuperfect_docs', 'access_docuperfect') && app(CandidatePractitionerService::class)->canAuthorise($u),
                'agency-documents' => fn (User $u) => $dp($u) && $p($u, 'view_agency_documents'),
                'shared-drive' => fn (User $u) => $dp($u) && $u->hasFeature('shared-drive') && $p($u, 'access_shared_drive'),
            ],
            DocumentLibraryTabs::class => [
                'packs' => fn (User $u) => $dp($u) && $p($u, 'access_docuperfect_packs', 'access_docuperfect'),
                'web-packs' => fn (User $u) => $dp($u) && $p($u, 'access_docuperfect_packs', 'access_docuperfect'),
                'clauses' => fn (User $u) => $dp($u) && $p($u, 'access_clause_library', 'access_docuperfect'),
                'compiler' => fn (User $u) => $dp($u) && $p($u, 'esign.compiler.view'),
            ],
            DocumentTemplatesTabs::class => [
                'templates' => $manage, 'field-groups' => $manage, 'named-fields' => $manage, 'types' => $manage, 'import' => $manage,
                'packages' => fn (User $u) => $dp($u) && $u->isOwnerRole() && $p($u, 'templates.transfer'),
            ],
            ESignSettingsTabs::class => [
                'presets' => $esign, 'recipient-templates' => $esign, 'finalisation' => $esign, 'signing-mode' => $esign,
            ],
        ];
    }

    public function test_every_tab_is_visible_exactly_when_its_old_link_was(): void
    {
        $users = [
            'agent defaults' => $this->userWith(null, 'agent'),
            'branch manager defaults' => $this->userWith(null, 'branch_manager'),
            'office staff defaults' => $this->userWith(null, 'office_admin'),
            'admin defaults' => $this->userWith(null, 'admin'),
            'agency documents only' => $this->userWith(['view_agency_documents', 'esign.settings', 'templates.transfer'], 'viewer'),
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
        $this->assertSame(5 * 18, $checked);
    }

    public function test_the_menu_has_six_items_and_every_tab_screen_opens_with_its_tab_marked(): void
    {
        $admin = $this->userWith(null, 'admin');
        $keys = ['create_docuperfect_docs', 'access_docuperfect', 'access_my_portal', 'view_dashboard'];
        foreach (self::HUBS as $class) {
            foreach ($class::tabs() as $def) {
                $keys = array_merge($keys, $def['permissions']);
            }
        }
        foreach (array_unique($keys) as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $this->actingAs($admin);

        $html = $this->get(route('docuperfect.dashboard'))->assertOk()->getContent();
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $items = [];
        // the top-level Documents panel (HR has a nested panel also titled "Documents" - PPRA FFC Letter - which is not this one)
        foreach ((new \DOMXPath($dom))->query('//div[contains(@class,"corex-nav-panel") and contains(@*[name()=":class"], "inStack(\'documents\')")][div[contains(@class,"corex-nav-panel-title") and normalize-space(.)="Documents"]]/a[contains(@class,"corex-nav-subitem")]') as $a) {
            $items[] = trim($a->textContent);
        }
        $this->assertSame(['Create Document', 'E-Sign Document', 'My Documents', 'Library', 'Templates', 'E-Sign Settings'], $items);

        $this->get(route('corex.hubs.doc-templates'))->assertRedirect(route('docuperfect.templates.index'));

        $opened = 0;
        foreach (self::HUBS as $class) {
            foreach ($class::forUser($admin) as $tab => $t) {
                $page = $this->get($t['url'])->assertOk()->getContent();
                $this->assertStringContainsString('data-hub-tabs aria-label="' . $class::label() . '"', $page, "{$tab}: the {$class::label()} bar");
                $this->assertMatchesRegularExpression('/data-hub-tab="' . $tab . '"[^>]*aria-current="page"/', $page, "{$tab}: its own tab is marked");
                $opened++;
            }
        }
        // every tab this admin is shown (Template Packages is System-Owner-only, so at most 17 of the 18)
        $this->assertGreaterThanOrEqual(16, $opened);

        // My E-Sign Documents and Authorise Documents share a screen; the filter decides the lit tab
        $this->assertMatchesRegularExpression('/data-hub-tab="esign"[^>]*aria-current="page"/', $this->get(route('docuperfect.esign.myDocuments'))->getContent());
    }
}
