<?php

namespace Tests\Feature\Features;

use App\Http\Middleware\EnforceFeatureRoutes;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STANDING RULE (Andre + Johan, 2026-10-07): every sidebar item is switchable under
 * Settings → Features. Behaviour proven here, end to end through the real middleware
 * stack and the real sidebar view:
 *
 *   - a feature that is OFF refuses its routes (404, not a hidden link only);
 *   - the sidebar item disappears, and a group whose children are all off hides itself;
 *   - the toggle beats the role permission;
 *   - every new toggle is on the Settings → Features screen and in the Setup Wizard;
 *   - everything is ON for an agency that never touched a toggle (nothing disappears).
 *
 * The structural half (every link has a toggle, no dead cross-links) is
 * FeatureNavGuardCoverageTest.
 */
class SidebarFeatureTogglesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        EnforceFeatureRoutes::flush();
        app(AgencyFeatureService::class)->forget();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(4)]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function off(Agency $agency, string ...$keys): void
    {
        foreach ($keys as $key) {
            AgencyFeature::create(['agency_id' => $agency->id, 'feature_key' => $key, 'enabled' => false]);
        }
        app(AgencyFeatureService::class)->forget();
    }

    /** A probe route inside a feature's route_names glob, behind the normal web + auth stack. */
    private function probe(string $name): string
    {
        $uri = '/__feature-probe/' . $name;
        Route::middleware(['web', 'auth'])->get($uri, fn () => 'probe-ok')->name($name);

        return $uri;
    }

    /** The sidebar is rendered into every authenticated page; My Profile is a cheap one. */
    private function sidebarHtml(User $user): string
    {
        return $this->actingAs($user)->get(route('agent.portal'))->assertOk()->getContent();
    }

    // ── Defaults: nothing disappears ─────────────────────────────────────────

    public function test_every_new_toggle_is_on_for_an_agency_that_never_touched_features(): void
    {
        $agency = $this->agency();
        $svc = app(AgencyFeatureService::class);

        foreach ([
            'rentals', 'rental-command-centre', 'rental-reports', 'rental-applications', 'rental-leases',
            'rental-take-on-import', 'rental-inspections', 'rental-faults', 'rental-work-orders', 'rental-notices',
            'rental-job-cards', 'rental-catalogue', 'rental-crews', 'deeds-capture', 'imported-stock',
            'buyer-pipeline', 'performance-roi-report', 'buyers-report', 'lead-response-report', 'performance-dashboards',
            'ppra-employment-letters', 'billing', 'soft-deletes', 'ppra-inspection-pack', 'misfiled-documents',
            'finance-engine', 'contact-governance',
        ] as $key) {
            $this->assertTrue($svc->enabled($key, $agency), "{$key} must default ON so nothing disappears for an existing agency");
        }
    }

    public function test_assistants_reads_the_existing_agency_switch_not_a_second_store(): void
    {
        $agency = $this->agency();
        $svc = app(AgencyFeatureService::class);

        $agency->forceFill(['assistants_enabled' => false])->save();
        $svc->forget();
        $this->assertFalse($svc->enabled('assistants', $agency));

        $agency->forceFill(['assistants_enabled' => true])->save();
        $svc->forget();
        $this->assertTrue($svc->enabled('assistants', $agency));
        $this->assertTrue($svc->isSwitchboard('assistants'), 'one switch: it is managed in Company Settings, not duplicated in agency_features');
        $this->assertNotContains('assistants', $svc->moduleFeatureKeys());
    }

    // ── Routes are refused, not just hidden ──────────────────────────────────

    public function test_a_switched_off_feature_refuses_its_routes_and_the_toggle_beats_the_permission(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $uri = $this->probe('corex.rental-job-cards.__probe');

        $this->actingAs($admin)->get($uri)->assertOk()->assertSee('probe-ok');

        $this->off($agency, 'rental-job-cards');
        $this->actingAs($admin)->get($uri)->assertNotFound();
    }

    public function test_switching_the_parent_off_refuses_every_child_route(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $leases = $this->probe('corex.leases.__probe');
        $crews = $this->probe('corex.rental-crews.__probe');
        $lens = $this->probe('corex.rentals.contacts.__probe');

        $this->off($agency, 'rentals');

        foreach ([$leases, $crews, $lens] as $uri) {
            $this->actingAs($admin)->get($uri)->assertNotFound();
        }
    }

    public function test_one_agency_switching_off_does_not_affect_another(): void
    {
        $a = $this->agency('Agency A');
        $b = $this->agency('Agency B');
        $adminA = $this->admin($a);
        $adminB = $this->admin($b);
        $uri = $this->probe('corex.deeds-capture.__probe');

        $this->off($a, 'deeds-capture');

        $this->actingAs($adminA)->get($uri)->assertNotFound();
        app(AgencyFeatureService::class)->forget();
        $this->actingAs($adminB)->get($uri)->assertOk();
    }

    public function test_a_route_two_features_own_needs_both_on(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        // The Rentals pipeline lens is the buyer pipeline board, reached through Rentals.
        $uri = $this->probe('corex.rentals.pipeline.__probe');

        $this->actingAs($admin)->get($uri)->assertOk();

        $this->off($agency, 'buyer-pipeline');
        $this->actingAs($admin)->get($uri)->assertNotFound();
    }

    public function test_the_enforcer_is_inert_for_guests_and_unrelated_routes(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $this->off($agency, 'rental-job-cards');

        Route::middleware('web')->get('/__feature-probe/public', fn () => 'public-ok')->name('corex.rental-job-cards.__public');
        $unrelated = $this->probe('some.unrelated.route');

        // No signed-in user => no agency to resolve => untouched (tenant/contractor secure links keep working).
        $this->get('/__feature-probe/public')->assertOk();
        $this->actingAs($admin)->get($unrelated)->assertOk();
    }

    public function test_assistants_routes_follow_the_company_settings_switch(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $uri = $this->probe('admin.assistants.__probe');

        $agency->forceFill(['assistants_enabled' => true])->save();
        app(AgencyFeatureService::class)->forget();
        $this->actingAs($admin)->get($uri)->assertOk();

        $agency->forceFill(['assistants_enabled' => false])->save();
        app(AgencyFeatureService::class)->forget();
        $this->actingAs($admin)->get($uri)->assertNotFound();
    }

    // ── The sidebar itself ───────────────────────────────────────────────────

    public function test_the_sidebar_shows_the_items_by_default_and_hides_only_the_switched_off_one(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $html = $this->sidebarHtml($admin);
        foreach (['corex.rental-job-cards.index', 'corex.leases.index', 'corex.deeds-capture.index', 'buyers-report.index', 'performance.agency-report'] as $r) {
            $this->assertStringContainsString('href="' . route($r) . '"', $html, "{$r} should be in the sidebar by default");
        }

        $this->off($agency, 'rental-job-cards', 'deeds-capture');
        $html = $this->sidebarHtml($admin);

        $this->assertStringNotContainsString('href="' . route('corex.rental-job-cards.index') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('corex.deeds-capture.index') . '"', $html);
        $this->assertStringContainsString('href="' . route('corex.leases.index') . '"', $html, 'a sibling item must stay');
    }

    public function test_switching_rentals_off_removes_the_whole_rentals_group(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->assertStringContainsString("push('rental-applications')", $this->sidebarHtml($admin));

        $this->off($agency, 'rentals');
        $html = $this->sidebarHtml($admin);

        $this->assertStringNotContainsString("push('rental-applications')", $html);
        $this->assertStringNotContainsString('href="' . route('corex.leases.index') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('corex.rentals.command-centre.index') . '"', $html);
    }

    public function test_the_reports_group_hides_itself_only_when_every_report_is_off(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->off($agency, 'performance-roi-report', 'buyers-report', 'lead-response-report');
        $this->assertStringContainsString("push('reports')", $this->sidebarHtml($admin), 'Suburb Report (Market intelligence) is still on, so the group stays');

        $this->off($agency, 'prospecting');
        $this->assertStringNotContainsString("push('reports')", $this->sidebarHtml($admin), 'all four off => the group hides itself');
    }

    public function test_lead_response_sits_in_the_reports_menu_and_obeys_its_own_switch(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $href = 'href="' . route('lead-response-report.index') . '"';

        $this->assertStringContainsString($href, $this->sidebarHtml($admin), 'Lead Response is in the Reports menu');

        $this->off($agency, 'lead-response-report');
        $this->assertStringNotContainsString($href, $this->sidebarHtml($admin), 'off => the link disappears');
        $this->assertStringContainsString('href="' . route('buyers-report.index') . '"', $this->sidebarHtml($admin), 'the other reports are untouched');

        $this->actingAs($admin)->get(route('lead-response-report.index'))->assertNotFound();
    }

    public function test_the_hr_group_hides_itself_when_payroll_and_the_ppra_letter_are_both_off(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->off($agency, 'ppra-employment-letters');
        $this->assertStringContainsString("push('hr')", $this->sidebarHtml($admin), 'Payroll is still on');

        $this->off($agency, 'payroll');
        $this->assertStringNotContainsString("push('hr')", $this->sidebarHtml($admin));
    }

    public function test_the_dashboard_keeps_today_calendar_and_tasks_when_the_performance_pages_are_off(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->off($agency, 'performance-dashboards');
        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString('href="' . route('command-center.today') . '"', $html);
        $this->assertStringContainsString('href="' . route('command-center.tasks') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('command-center.reporting.agent') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('command-center.performance') . '"', $html);
    }

    // ── Settings screen + Setup Wizard ───────────────────────────────────────

    public function test_every_new_toggle_is_on_the_settings_features_screen_and_in_the_setup_wizard(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $this->actingAs($admin);

        $settings = view('corex.settings._features')->render();
        $wizard = view('agency-setup.steps.capabilities-modules', ['agency' => $agency])->render();

        foreach ([
            'rental-command-centre', 'rental-reports', 'rental-applications', 'rental-leases', 'rental-take-on-import',
            'rental-inspections', 'rental-faults', 'rental-work-orders', 'rental-notices', 'rental-job-cards',
            'rental-catalogue', 'rental-crews', 'deeds-capture', 'imported-stock', 'buyer-pipeline',
            'performance-roi-report', 'buyers-report', 'lead-response-report', 'performance-dashboards', 'ppra-employment-letters',
            'billing', 'soft-deletes', 'ppra-inspection-pack', 'misfiled-documents', 'finance-engine', 'contact-governance',
        ] as $key) {
            $this->assertStringContainsString('name="' . $key . '" value="1"', $settings, "{$key} missing from Settings → Features");
            $this->assertStringContainsString('name="' . $key . '" value="1"', $wizard, "{$key} missing from the Setup Wizard");
        }

        $this->assertStringContainsString('Rentals', $settings);
        $this->assertStringContainsString('Company &amp; Admin', $settings);
    }
}
