<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Features page (.ai/specs/features-page.md §5, style 1 "Clean list"): its own page, out of Settings.
 * Rendered for real over HTTP — a compiled Blade view proves nothing about whether it renders.
 *
 * Seeding mirrors RoleManagerFunctionalTest: without real grants PermissionService falls back to allow-all and
 * the "agent is refused" assertions would pass for the wrong reason.
 */
final class FeaturesPageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $roles = [
            ['name' => 'super_admin', 'label' => 'System Owner',  'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',       'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'agent',       'label' => 'Agent',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
        ];
        foreach ($roles as &$r) {
            $r += ['agency_id' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Page ' . uniqid(), 'slug' => 'page-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true,
        ]);
        $this->admin = $mk('admin');
        $this->agent = $mk('agent');

        $this->assertGreaterThan(0, RolePermission::where('agency_id', $this->agency->id)->count(), 'agency must be provisioned (else allow-all)');
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    public function test_the_page_renders_with_search_every_feature_and_the_scrolling_list(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.features.index'))
            ->assertOk()
            ->assertSee('Search features', false)
            ->assertSee('featuresPage(', false)
            ->assertSee('overflow-y-auto', false)         // the list is the scrolling region
            ->assertSee('Save features', false)
            ->getContent();

        foreach (app(AgencyFeatureService::class)->catalogue() as $key => $def) {
            $this->assertStringContainsString(e($def['label']), $html, "{$key} ({$def['label']}) must be listed");
        }
        $this->assertStringContainsString('Always on', $html);
    }

    public function test_every_module_feature_stays_in_the_form_so_search_and_filter_can_never_change_a_switch(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.features.index'))->assertOk()->getContent();

        foreach (app(AgencyFeatureService::class)->moduleFeatureKeys() as $key) {
            $this->assertMatchesRegularExpression(
                '/<input type="hidden" name="' . preg_quote($key, '/') . '" value="[01]"/',
                $html,
                "module feature {$key} must have its hidden companion input in the DOM at all times"
            );
        }
        // Filtering hides rows with x-show; it must never remove them (no x-if / x-for around the rows). Checked on
        // the Features form only — the shared layout around it legitimately uses x-if elsewhere.
        preg_match('#<form method="POST" action="[^"]*settings/features".*?</form>#s', $html, $m);
        $this->assertNotEmpty($m, 'the Features form must be on the page');
        $this->assertStringNotContainsString('x-if=', $m[0]);
        $this->assertStringContainsString("x-show=\"matches('rentals')\"", $m[0]);
        // A child blocked by a parent that is off must post nothing.
        $this->assertStringContainsString(':disabled="blocked(', $html);
    }

    public function test_it_is_gated_by_the_features_permission(): void
    {
        $this->actingAs($this->agent)->get(route('corex.features.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('corex.features.index'))->assertOk();
    }

    public function test_the_old_settings_address_redirects_to_the_new_page(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings', ['s' => 'features']))
            ->assertRedirect(route('corex.features.index'));
    }

    public function test_features_is_gone_from_the_settings_rail_and_sections(): void
    {
        $settings = file_get_contents(resource_path('views/corex/settings.blade.php'));

        $this->assertStringNotContainsString("'key'=>'features'", $settings);
        $this->assertStringNotContainsString("activeSection === 'features'", $settings);
        $this->assertStringNotContainsString('corex.settings._features', $settings);
        $this->assertFileDoesNotExist(resource_path('views/corex/settings/_features.blade.php'));
    }

    public function test_the_sidebar_has_a_features_entry_gated_by_the_permission(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $this->assertMatchesRegularExpression(
            "/@permission\('agency_features\.manage'\)\s*<a href=\"\{\{ route\('corex\.features\.index'\) \}\}\"/",
            $sidebar
        );

        // ...and it really renders for an admin who holds it.
        $this->actingAs($this->admin)->get(route('corex.features.index'))
            ->assertSee('href="' . route('corex.features.index') . '"', false);
    }

    public function test_settings_is_a_sub_menu_holding_the_settings_page_and_features_and_features_left_company(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));

        // One "Settings" slide-panel group; Settings page + Features page both inside it.
        $this->assertStringContainsString("push('settings')", $sidebar);
        $this->assertStringContainsString('<div class="corex-nav-panel-title">Settings</div>', $sidebar);
        preg_match('#<div class="corex-nav-panel-title">Settings</div>(.*?)</div>\s*</div>#s', $sidebar, $group);
        $this->assertNotEmpty($group, 'the Settings group panel must exist');
        $this->assertStringContainsString("route('corex.settings')", $group[1]);
        $this->assertStringContainsString("route('corex.features.index')", $group[1]);

        // Features no longer sits in the Company group.
        preg_match('#<div class="corex-nav-panel-title">Company</div>(.*?)</div>\s*</div>#s', $sidebar, $company);
        $this->assertNotEmpty($company);
        $this->assertStringNotContainsString("route('corex.features.index')", $company[1]);

        // The Features page lights up the Settings group.
        $this->assertStringContainsString("'corex.features.*',", $sidebar);
        $this->assertStringContainsString('$activeGroup = \'settings\';', $sidebar);
    }

    public function test_saving_returns_to_the_new_page_and_the_page_shows_the_saved_state(): void
    {
        $response = $this->actingAs($this->admin)->post(route('corex.settings.features.update'), ['rentals' => '0']);
        $response->assertRedirect(route('corex.features.index'))->assertSessionHas('success');

        $html = $this->actingAs($this->admin)->get(route('corex.features.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="rentals" value="0"/', $html, 'rentals now saved as off');
        $this->assertDoesNotMatchRegularExpression('/<input type="hidden" name="rentals" value="1"/', $html);

        // Switching it back on round-trips too.
        $this->actingAs($this->admin)->post(route('corex.settings.features.update'), ['rentals' => '1']);
        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="rentals" value="1"/',
            $this->actingAs($this->admin)->get(route('corex.features.index'))->getContent()
        );
    }

    public function test_a_child_whose_parent_is_off_is_rendered_blocked_and_posts_nothing(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.features.update'), ['payroll' => '0']);

        $html = $this->actingAs($this->admin)->get(route('corex.features.index'))->assertOk()->getContent();

        // leave depends_on payroll: its input renders with a real `disabled` attribute (a disabled input is not
        // submitted, so its stored preference is untouched). The `:disabled` Alpine binding is always present, so
        // match the plain attribute at the end of the tag.
        $serverDisabled = fn (string $key) => '/<input type="hidden" name="' . $key . '" value="[01]"[^>]*\sdisabled(="disabled")?\s*\/?>/';
        $this->assertMatchesRegularExpression($serverDisabled('leave'), $html, 'leave is blocked while payroll is off');
        $this->assertDoesNotMatchRegularExpression($serverDisabled('rentals'), $html, 'an unblocked module must not be disabled');
        $this->assertStringContainsString('Turn on', $html);
    }
}
