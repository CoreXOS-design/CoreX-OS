<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md steps 7, 8 and 10 - the menu side (AT-454 lane, Johan 20 Oct 2026).
 *
 * Step 7: Branch Performance, Agency Performance and both Worksheet Market pages live in Reports and are gone from Agency Tracker; opening
 * any of them (or an agent's drill-down) opens and lights its Reports link; the TV code cards are off both Performance pages.
 * Step 8: TV Messages and P24 Auto-Import are gone from Agency Tracker; their pages open and light the Settings group.
 * Step 10: Calculators is its own menu for anyone holding access_calculators (five tools + the hub), no longer inside Agency Tracker or Trust
 * Interest; a user holding only access_calculators gets no empty Trust Interest group; every page opens and lights Calculators.
 */
final class ReportsTvCalculatorsMenuTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        foreach (['admin', 'agent'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        foreach ([
            'sidebar.section.agents', 'sidebar.section.tools', 'sidebar.section.admin', 'access_agency_tracker', 'view_performance', 'view_branch_stats', 'view_company_stats',
            'access_worksheet_market', 'edit_worksheet', 'view_worksheet', 'access_calculators', 'access_settings', 'manage_tv_messages',
            'access_trust_interest', 'access_deposit_calculator', 'manage_targets',
        ] as $key) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        foreach (['sidebar.section.agents', 'access_calculators'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        $this->feature('tv-display', true);
    }

    private function feature(string $key, bool $on): void
    {
        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => $key], ['enabled' => $on, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    /** The links of one sidebar panel as [label => lit?]. */
    private function panel(string $html, string $title): array
    {
        $parts = explode('<div class="corex-nav-panel-title">' . $title . '</div>', $html, 2);
        if (count($parts) < 2) {
            return [];
        }
        $next = strpos($parts[1], 'corex-nav-panel-title">');
        $seg = $next === false ? $parts[1] : substr($parts[1], 0, $next);
        preg_match_all('~<a href="[^"]*" class="corex-nav-subitem( active)? *"[^>]*>\s*(?:<span>)?\s*([^<]+?)\s*(?:</span>|</a>)~', $seg, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[trim(html_entity_decode($row[2]))] = trim($row[1]) === 'active';
        }

        return $out;
    }

    private function openPanels(string $html): array
    {
        preg_match_all("~corex-nav-panel is-open\" :class=\"\{ 'is-open': inStack\('([a-z0-9-]+)'\) \}\"~", $html, $m);

        return $m[1];
    }

    private function page(User $u, string $route, array $q = []): string
    {
        return $this->actingAs($u)->get(route($route, $q))->assertOk()->getContent();
    }

    // ── step 7 ──

    public function test_the_performance_and_worksheet_market_pages_live_in_reports_not_agency_tracker(): void
    {
        $html = $this->page($this->admin, 'admin.performance');
        $reports = $this->panel($html, 'Reports');
        $tracker = $this->panel($html, 'Agency Tracker');

        foreach (['Branch Performance', 'Agency Performance', 'Branch Worksheet Market', 'Agency Worksheet Market'] as $label) {
            $this->assertArrayHasKey($label, $reports, "{$label} is in Reports");
            $this->assertArrayNotHasKey($label, $tracker);
        }
        foreach (['Performance', 'Worksheet Market', 'TV Messages', 'P24 Auto-Import'] as $old) {
            $this->assertArrayNotHasKey($old, $tracker, "no old '{$old}' item left in Agency Tracker");
        }
    }

    public function test_each_moved_page_opens_reports_and_lights_its_own_link_only(): void
    {
        foreach ([
            ['admin.performance', 'Agency Performance'],
            ['bm.performance', 'Branch Performance'],
            ['bm.worksheet.market', 'Branch Worksheet Market'],
            ['admin.worksheet-market', 'Agency Worksheet Market'],
        ] as [$route, $lit]) {
            $html = $this->page($this->admin, $route);
            $this->assertContains('reports', $this->openPanels($html), "{$route} opens Reports");
            $this->assertNotContains('agency-tracker', $this->openPanels($html), "{$route} no longer opens Agency Tracker");
            $this->assertSame([$lit], array_keys(array_filter($this->panel($html, 'Reports'))), "{$route} lights {$lit} only");
        }
    }

    public function test_the_tv_code_cards_are_off_both_performance_pages(): void
    {
        foreach (['admin.performance', 'bm.performance'] as $route) {
            $html = $this->page($this->admin, $route);
            $this->assertStringNotContainsString('tv-code', $html, "{$route} carries no TV code form");
            $this->assertStringNotContainsString('TV Access Codes', $html);
            $this->assertStringNotContainsString('>TV Code<', $html);
        }
    }

    // ── step 8 ──

    public function test_the_tv_display_and_p24_pages_open_and_light_settings(): void
    {
        foreach (['corex.settings.tv-display.index', 'admin.minion.setup'] as $route) {
            $html = $this->page($this->admin, $route);
            $this->assertContains('settings', $this->openPanels($html), "{$route} opens Settings");
            $this->assertNotContains('agency-tracker', $this->openPanels($html));
            $this->assertTrue($this->panel($html, 'Settings')['Settings'] ?? false, "{$route} lights the Settings link");
        }
    }

    // ── step 10 ──

    public function test_calculators_is_its_own_menu_with_the_five_tools_and_the_hub(): void
    {
        $html = $this->page($this->admin, 'tools.commission');
        $calc = $this->panel($html, 'Calculators');

        foreach (['Commission Calculator', 'Evaluation Certificate', 'History & Logs', 'More Calculators'] as $label) {
            $this->assertArrayHasKey($label, $calc);
        }
        $tracker = $this->panel($html, 'Agency Tracker');
        foreach (['Commission Calculator', 'Evaluation Certificate', 'My Evaluations', 'Pending Authorisations', 'History & Logs'] as $label) {
            $this->assertArrayNotHasKey($label, $tracker, "{$label} left Agency Tracker");
        }
        $this->assertArrayNotHasKey('Calculators', $this->panel($html, 'Trust Interest'), 'the hub no longer sits in Trust Interest');
        $this->assertArrayHasKey('Trust Interest Register', $this->panel($html, 'Trust Interest'));
        $this->assertSame(0, preg_match('/\bvaluation\b/i', $this->flatten($calc)), 'the word valuation is never used (Evaluation Certificate is the name)');
    }

    private function flatten(array $labels): string
    {
        return implode(' | ', array_keys($labels));
    }

    public function test_every_calculator_page_opens_and_lights_calculators(): void
    {
        $cases = [
            ['tools.commission', [], 'Commission Calculator'],
            ['tools.commission', ['section' => 'history'], 'History & Logs'],
            ['tools.cma', [], 'Evaluation Certificate'],
            ['calculators.index', [], 'More Calculators'],
        ];
        foreach ($cases as [$route, $q, $lit]) {
            $html = $this->page($this->agent, $route, $q);
            $this->assertContains('calculators', $this->openPanels($html), "{$route} opens Calculators");
            $this->assertSame([$lit], array_keys(array_filter($this->panel($html, 'Calculators'))), "{$route} lights {$lit} only");
        }
    }

    public function test_an_agent_holding_only_access_calculators_sees_calculators_and_no_empty_trust_interest(): void
    {
        $html = $this->page($this->agent, 'tools.commission');
        $this->assertArrayHasKey('Commission Calculator', $this->panel($html, 'Calculators'));
        $this->assertStringNotContainsString('push(\'trust-interest\')', $html, 'no empty Trust Interest group');
        $this->assertStringNotContainsString('push(\'reports\')', $html);
    }

    public function test_a_user_without_access_calculators_sees_no_calculators_menu(): void
    {
        RolePermission::where('role', 'agent')->where('permission_key', 'access_calculators')->delete();
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => 'own', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $html = $this->page($this->agent, 'deals-dr2.index');
        $this->assertStringNotContainsString("push('calculators')", $html);
        $this->actingAs($this->agent)->get(route('tools.commission'))->assertForbidden();
    }

    public function test_switching_the_calculators_feature_off_hides_the_menu(): void
    {
        $this->feature('calculators', false);
        $html = $this->page($this->admin, 'admin.performance');
        $this->assertStringNotContainsString("push('calculators')", $html);
    }
}
