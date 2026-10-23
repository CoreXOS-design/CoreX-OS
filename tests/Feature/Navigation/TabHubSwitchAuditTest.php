<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Support\Navigation\TabHubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Full switch audit (Andre, 22 Oct 2026) for every tabbed menu item (.ai/specs/*-menu-tabs.md): drive the REAL screens an admin uses -
 * Settings -> Features (POST corex.settings.features.update) and Role Manager (POST corex.role-manager.save) - and for every feature key and
 * every permission any tab depends on, prove:
 *   1. HIDDEN  - the tab drops out of the tab bar on its sibling screens, and the menu item disappears when no tab is left;
 *   2. BACK    - switching it on again brings the tab back;
 *   3. LOCKED  - opening the tab's screen by direct URL while it is off. This is the screen's OWN route protection, not something the
 *                menu change touched; screens that still open are pinned in KNOWN_OPEN (reported for their own tickets, not fixed here),
 *                so a new gap fails this test and a fixed one asks to be unpinned.
 * Set TAB_AUDIT_OUT=<file> to get the full matrix as JSON.
 */
final class TabHubSwitchAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Screens that still open by direct URL with the switch named here OFF. Pre-existing route protection gaps - see the audit report
     * .ai/audits/tab-hub-switch-audit-2026-10-22.md. Format: "<tab route> | <feature:key|permission:key>".
     */
    private const KNOWN_OPEN = [
        // the Compliance PANEL key: the menu hid every Compliance link without it, but each screen only checks its own key
        'compliance.agency-settings.index | permission:access_compliance',
        'compliance.communications.index | feature:compliance',
        'compliance.communications.index | permission:access_compliance',
        'compliance.document-types.index | permission:access_compliance',
        'compliance.policy.dashboard.index | permission:access_compliance',
        'compliance.policy.index | permission:access_compliance',
        'compliance.rmcp.dashboard.index | permission:access_compliance',
        'compliance.rmcp.index | permission:access_compliance',
        'compliance.screening.dashboard.index | permission:access_compliance',
        // a key the menu checked that the screen's route does not
        'compliance.screening.dashboard.index | permission:manage_employee_screenings',
        'compliance.verification.index | permission:access_compliance',
        'compliance.whistleblow.index | permission:access_compliance',
        'corex.core-matches.index | permission:access_core_matches',
        'docuperfect.clauses.index | permission:access_clause_library',
        'docuperfect.esign.myDocuments | permission:create_docuperfect_docs',
        'docuperfect.packs.index | permission:access_docuperfect_packs',
        'docuperfect.web-packs.index | permission:access_docuperfect_packs',
        // Agency Documents is a My Portal screen listed under Documents - it does not belong to the docuperfect module
        'my-portal.agency-documents | feature:docuperfect',
    ];

    /** Tabs no agency switch controls: System-Owner-only, or gated by a role name / a named-person setting rather than a permission. */
    private const NOT_A_SWITCH = ['doc-templates' => ['packages'], 'compliance-agents' => ['agents']];

    private Agency $agency;

    private User $admin;

    private array $report = [];

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
        $this->agency = Agency::create(['name' => 'Audit Agency ' . uniqid(), 'slug' => 'audit-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        // The admin holds every key any tab checks, plus the keys to run Features and Role Manager.
        $keys = ['access_my_portal', 'view_dashboard', 'agency_features.manage', 'access_role_manager', 'edit_permissions', 'leases.view'];
        foreach (TabHubs::HUBS as $class) {
            foreach ($class::tabs() as $t) {
                $keys = array_merge($keys, $t['permissions'] ?? []);
            }
        }
        foreach (array_unique($keys) as $key) {
            RolePermission::firstOrCreate(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agency->forceFill(['rental_application_ro_user_ids' => [$this->admin->id]])->save();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    // ── the two real switches ────────────────────────────────────────────────────────────────────────

    /** Settings -> Features, as the admin clicks it. A switchboard key (e.g. core-matches) is saved by its own settings saver: its store. */
    private function feature(string $key, bool $on): string
    {
        $svc = app(AgencyFeatureService::class);
        if ($svc->isSwitchboard($key)) {
            $store = AgencyFeatureService::SWITCHBOARD_STORES[$key];
            $store['type'] === 'perf'
                ? PerformanceSetting::set($store['key'], $on ? 1 : 0, $this->agency->id)
                : $this->agency->forceFill([$store['key'] => $on])->save();
            $svc->forget($this->agency->id);

            return 'switchboard setting';
        }
        $this->post(route('corex.settings.features.update'), [$key => $on ? '1' : '0'])->assertRedirect();
        $svc->forget($this->agency->id);

        return 'Features page';
    }

    /** Role Manager, as the admin saves it: the role's full matrix, minus (or plus) one key. */
    private function permission(string $key, bool $on): void
    {
        $current = RolePermission::where('role', 'admin')->where('agency_id', $this->agency->id)->pluck('permission_key')->all();
        $keys = $on ? array_unique(array_merge($current, [$key])) : array_values(array_diff($current, [$key]));
        $this->post(route('corex.role-manager.save'), ['role' => 'admin', 'permissions' => array_fill_keys($keys, '1')])->assertSessionHasNoErrors();
        PermissionService::clearCache();
        $this->assertSame($on, RolePermission::where('role', 'admin')->where('agency_id', $this->agency->id)->where('permission_key', $key)->exists(), "Role Manager saved '{$key}' " . ($on ? 'on' : 'off'));
    }

    // ── what the user sees ───────────────────────────────────────────────────────────────────────────

    /** @return list<array{hub: string, class: string, tab: string, def: array}> every switch-controlled tab */
    private function tabs(): array
    {
        $out = [];
        foreach (TabHubs::HUBS as $hub => $class) {
            foreach ($class::tabs() as $tab => $def) {
                if (! in_array($tab, self::NOT_A_SWITCH[$hub] ?? [], true)) {
                    $out[] = compact('hub', 'class', 'tab', 'def');
                }
            }
        }

        return $out;
    }

    private function url(array $def): string
    {
        return route($def['route'], $def['query'] ?? []);
    }

    /** Check one switch: every tab it controls is hidden (bar + menu), its screen's direct-URL answer is recorded. */
    private function checkOff(string $switch, array $affected, array &$problems): void
    {
        $byHub = [];
        foreach ($affected as $a) {
            $byHub[$a['hub']][] = $a;
        }
        foreach ($byHub as $hub => $items) {
            $class = $items[0]['class'];
            $visible = $class::forUser($this->admin);
            foreach ($items as $a) {
                if (isset($visible[$a['tab']])) {
                    $problems[] = "{$hub} > {$a['tab']}: still offered with {$switch} off";
                }
            }
            // the rendered bar on a sibling tab that is still open, or - with none left - the menu item is gone and its entry refuses
            $witness = collect($visible)->first(fn ($t, $k) => ($class::tabs()[$k]['match'] ?? null) !== []);
            if ($witness) {
                $html = $this->get($witness['url'])->getContent();
                foreach ($items as $a) {
                    if (str_contains($html, 'data-hub-tab="' . $a['tab'] . '"')) {
                        $problems[] = "{$hub} > {$a['tab']}: still in the rendered tab bar with {$switch} off";
                    }
                }
            } else {
                if ($this->get(route('corex.hubs.' . $hub))->status() !== 403) {
                    $problems[] = "{$hub}: menu entry does not refuse with every tab off ({$switch})";
                }
                if (str_contains($this->get(route('agent.portal'))->getContent(), route('corex.hubs.' . $hub) . '"')) {
                    $problems[] = "{$hub}: menu item still shown with every tab off ({$switch})";
                }
            }
            foreach ($items as $a) {
                if (($a['def']['match'] ?? null) === []) {
                    continue; // a tab that jumps to another screen (Link My WhatsApp) has no screen of its own
                }
                $status = $this->get($this->url($a['def']))->status();
                $this->report[] = ['tab' => "{$hub} > {$a['tab']}", 'route' => $a['def']['route'], 'switch' => $switch, 'direct_url_status' => $status];
            }
        }
    }

    private function checkBack(string $switch, array $affected, array &$problems): void
    {
        foreach ($affected as $a) {
            if (! isset($a['class']::forUser($this->admin)[$a['tab']])) {
                $problems[] = "{$a['hub']} > {$a['tab']}: not back after switching {$switch} on again";
            }
        }
    }

    public function test_every_feature_and_role_manager_switch_hides_its_tabs_and_brings_them_back(): void
    {
        $problems = [];

        // 0. everything on: every switch-controlled tab is offered
        foreach ($this->tabs() as $a) {
            if (! isset($a['class']::forUser($this->admin)[$a['tab']])) {
                $problems[] = "{$a['hub']} > {$a['tab']}: not offered with everything on";
            }
        }
        $this->assertSame([], $problems, "Start state:\n" . implode("\n", $problems));

        // 1. Settings -> Features, one feature key at a time
        $features = [];
        foreach ($this->tabs() as $a) {
            if (! empty($a['def']['feature'])) {
                $features[$a['def']['feature']][] = $a;
            }
        }
        foreach ($features as $key => $affected) {
            $via = $this->feature($key, false);
            $this->checkOff("feature:{$key} ({$via})", $affected, $problems);
            $this->feature($key, true);
            $this->checkBack("feature:{$key}", $affected, $problems);
        }

        // 2. Role Manager, one permission at a time
        $perms = [];
        foreach ($this->tabs() as $a) {
            foreach ($a['def']['permissions'] ?? [] as $key) {
                $perms[$key][] = $a;
            }
        }
        foreach ($perms as $key => $affected) {
            $this->permission($key, false);
            $this->checkOff("permission:{$key}", $affected, $problems);
            $this->permission($key, true);
            $this->checkBack("permission:{$key}", $affected, $problems);
        }

        if ($out = getenv('TAB_AUDIT_OUT')) {
            file_put_contents($out, json_encode(['features' => array_keys($features), 'permissions' => array_keys($perms), 'rows' => $this->report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->assertGreaterThan(30, count($features) + count($perms), 'the audit walked the switches');
        $this->assertSame([], $problems, "Switch problems:\n" . implode("\n", $problems));

        // 3. direct URL: screens that still open while switched off must be exactly the known, reported gaps
        $open = collect($this->report)->filter(fn ($r) => $r['direct_url_status'] === 200)
            ->map(fn ($r) => $r['route'] . ' | ' . preg_replace('/ \(.*\)$/', '', $r['switch']))->unique()->sort()->values()->all();
        $this->assertSame(self::KNOWN_OPEN, $open, "Screens that open by direct URL while switched off (pin known gaps in KNOWN_OPEN, report new ones):\n" . implode("\n", $open));
    }
}
