<?php

namespace Tests\Feature\Features;

use App\Events\AgencyFeatureToggled;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CoreX Per-Agency Feature Registry — Phase 1 gate plumbing.
 * Spec: .ai/specs/corex-feature-registry.md §12/§13.
 *
 * Proves the universal gate: default resolution, per-agency override,
 * core-always-on, depends_on cascade, env kill-switch AND, CheckFeature 404,
 * multi-tenant isolation, request-cache, unknown-key fail-closed, config validity.
 */
class AgencyFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function svc(): AgencyFeatureService
    {
        $s = app(AgencyFeatureService::class);
        $s->forget(); // start each assertion from a cold memo
        return $s;
    }

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name)]);
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

    private function override(Agency $agency, string $key, bool $enabled): void
    {
        AgencyFeature::create([
            'agency_id'   => $agency->id,
            'feature_key' => $key,
            'enabled'     => $enabled,
        ]);
    }

    // ── 1. Default resolution from the registry ──────────────────────────────

    public function test_resolves_registry_default_when_no_row(): void
    {
        $agency = $this->agency();

        // presentations default ON, rentals default OFF (config/corex-features.php).
        $this->assertTrue($this->svc()->enabled('presentations', $agency));
        $this->assertFalse($this->svc()->enabled('rentals', $agency));
    }

    // ── 2. Per-agency override ───────────────────────────────────────────────

    public function test_per_agency_override_wins_over_default(): void
    {
        $agency = $this->agency();

        $this->override($agency, 'rentals', true);      // default off → forced on
        $this->override($agency, 'presentations', false); // default on → forced off

        $this->assertTrue($this->svc()->enabled('rentals', $agency));
        $this->assertFalse($this->svc()->enabled('presentations', $agency));
    }

    // ── 3. Core is always on (never toggleable) ──────────────────────────────

    public function test_core_feature_is_always_on_even_with_off_row(): void
    {
        $agency = $this->agency();
        $this->override($agency, 'properties', false); // a stale/hostile off row

        $this->assertTrue($this->svc()->enabled('properties', $agency), 'core short-circuits before any override');
        $this->assertTrue($this->svc()->enabled('contacts', $agency));
        $this->assertTrue($this->svc()->enabled('dashboard', $agency));
    }

    // ── 4. depends_on cascade ────────────────────────────────────────────────

    public function test_depends_on_parent_off_forces_child_off(): void
    {
        $agency = $this->agency();

        // leave depends_on payroll; both default OFF.
        $this->override($agency, 'leave', true); // child forced on...
        // ...but payroll (parent) is still off (default), so leave resolves off.
        $this->assertFalse($this->svc()->enabled('leave', $agency), 'child off while parent off');

        // Turn the parent on → child (still on) now resolves on.
        $this->override($agency, 'payroll', true);
        $this->assertTrue($this->svc()->enabled('leave', $agency), 'child on once parent on');
    }

    // ── 5. Global env kill-switch (outer AND) ────────────────────────────────

    public function test_env_kill_switch_forces_off_for_everyone(): void
    {
        $agency = $this->agency();
        $this->override($agency, 'presentations', true); // agency wants it on

        // presentations maps to global_flag 'presentations'. Kill it globally.
        Config::set('features.presentations', false);

        $this->assertFalse(
            $this->svc()->enabled('presentations', $agency),
            'a global kill-switch off beats any per-agency ON'
        );
    }

    // ── 6. CheckFeature middleware 404s an off feature ───────────────────────

    public function test_middleware_404s_off_feature_and_passes_on_feature(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        Route::middleware(['web', 'auth', 'feature:rentals'])
            ->get('/__test/rentals', fn () => 'ok')->name('test.rentals');

        // rentals default OFF → 404 (invisible, not 403).
        $this->actingAs($admin)->get('/__test/rentals')->assertNotFound();

        // Turn it on for this agency, bust the memo, retry → 200.
        $this->override($agency, 'rentals', true);
        app(AgencyFeatureService::class)->forget();
        $this->actingAs($admin)->get('/__test/rentals')->assertOk()->assertSee('ok');
    }

    // ── 7. Multi-tenant isolation ────────────────────────────────────────────

    public function test_one_agencys_override_never_leaks_to_another(): void
    {
        $a = $this->agency('Home Finders Coastal');
        $b = $this->agency('Blue Horizon Properties');

        $this->override($a, 'rentals', true); // only A enables rentals

        $this->assertTrue($this->svc()->enabled('rentals', $a));
        $this->assertFalse($this->svc()->enabled('rentals', $b), 'B must not see A\'s override');
    }

    // ── 8. Request cache — one query per agency per request ──────────────────

    public function test_resolution_is_request_cached_one_query(): void
    {
        $agency = $this->agency();
        $svc = $this->svc();

        $count = 0;
        DB::listen(function ($q) use (&$count) {
            if (str_contains($q->sql, 'agency_features')) {
                $count++;
            }
        });

        // Five reads across different keys → one query for the whole map.
        $svc->enabled('rentals', $agency);
        $svc->enabled('presentations', $agency);
        $svc->enabled('payroll', $agency);
        $svc->enabled('leave', $agency);
        $svc->all($agency);

        $this->assertSame(1, $count, 'the resolved map is computed once per agency per request');
    }

    // ── 9. Unknown key fails closed ──────────────────────────────────────────

    public function test_unknown_feature_key_resolves_false(): void
    {
        $agency = $this->agency();
        $this->assertFalse($this->svc()->enabled('totally-made-up-module', $agency));
    }

    // ── 9a. Owner in the global context bypasses feature flags ───────────────

    /** A system-owner role row (is_owner true, global) + a user wearing it. */
    private function owner(): User
    {
        Role::forceCreate([
            'name'     => 'super_admin',
            'label'    => 'System Owner',
            'is_owner' => true,
            'agency_id' => null,
        ]);
        Role::clearCache();

        return User::factory()->create([
            'agency_id' => null,
            'branch_id' => null,
            'role'      => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_owner_in_global_context_sees_every_feature(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        // payroll + leave both default OFF and the owner has NO agency, so without
        // the bypass they resolve false (the bug: Super Admin lost Branch Manager
        // items an agency admin could see). The owner-global bypass turns them on.
        $this->assertTrue($this->svc()->enabled('payroll'), 'owner (no agency) sees a default-off module');
        $this->assertTrue($this->svc()->enabled('leave'), 'owner (no agency) sees a depends_on child too');

        // The typo guard still runs first — an unknown key stays false even for the owner.
        $this->assertFalse($this->svc()->enabled('totally-made-up-module'), 'owner never masks a bad key');
    }

    public function test_owner_bypass_does_not_apply_to_an_explicit_agency(): void
    {
        $owner  = $this->owner();
        $agency = $this->agency();
        $this->actingAs($owner);

        // Passing an explicit agency = "show me THIS agency's real config" (the
        // switcher preview). The bypass must NOT fire — payroll is off for them.
        $this->assertFalse(
            $this->svc()->enabled('payroll', $agency),
            'an explicit agency resolves that agency\'s true config, never bypassed'
        );

        // ...and once the agency turns it on, the owner sees it on — normal resolution.
        $this->override($agency, 'payroll', true);
        $this->assertTrue($this->svc()->enabled('payroll', $agency));
    }

    public function test_non_owner_with_no_agency_gets_no_bypass(): void
    {
        // A non-owner user with no agency must still fail-closed on a default-off
        // module — the bypass is owner-only, not "anyone lacking an agency".
        $user = User::factory()->create([
            'agency_id' => null,
            'branch_id' => null,
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        $this->assertFalse($this->svc()->enabled('payroll'), 'non-owner never bypasses feature gating');
    }

    // ── 10. @feature directive + feature() helper ────────────────────────────

    public function test_feature_directive_and_helper(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->actingAs($admin);

        // @feature is a Blade::if directive. Whitespace MUST precede each directive
        // (Blade's \B@ regex skips an @ that follows a word char), and the plain
        // @else is the generic else (@elsefeature would be an else-IF needing an arg).
        $tpl = "@feature('rentals') ON @else OFF @endfeature";

        app(AgencyFeatureService::class)->forget();
        $this->assertStringContainsString('OFF', Blade::render($tpl));

        $this->override($agency, 'rentals', true);
        app(AgencyFeatureService::class)->forget();
        $this->assertStringContainsString('ON', Blade::render($tpl));

        $this->assertTrue(feature('presentations')); // helper, default-on module
    }

    // ── 11. Domain event busts the cache ─────────────────────────────────────

    public function test_toggled_event_busts_the_request_cache(): void
    {
        $agency = $this->agency();
        $svc = app(AgencyFeatureService::class);
        $svc->forget();

        $this->assertFalse($svc->enabled('rentals', $agency)); // caches map (rentals off)

        $this->override($agency, 'rentals', true);
        event(new AgencyFeatureToggled($agency->id, 'rentals', true, null)); // listener forgets

        $this->assertTrue($svc->enabled('rentals', $agency), 'cache busted → fresh read sees the new row');
    }

    // ── Phase 2: switchboard store adapter ───────────────────────────────────

    public function test_switchboard_keys_read_their_existing_store_not_agency_features(): void
    {
        $agency = $this->agency();

        // core-matches reads PerformanceSetting matches_enabled (default on).
        $this->assertTrue($this->svc()->enabled('core-matches', $agency));
        \App\Models\PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => 0]);
        $this->assertFalse($this->svc()->enabled('core-matches', $agency), 'core-matches follows matches_enabled');

        // public-website reads the agencies column (genuinely per-agency).
        $this->assertFalse($this->svc()->enabled('public-website', $agency)); // default off
        $agency->forceFill(['website_enabled' => true])->save();
        $this->assertTrue($this->svc()->enabled('public-website', $agency), 'public-website follows agencies.website_enabled');
    }

    public function test_agency_features_row_is_ignored_for_a_switchboard_key(): void
    {
        $agency = $this->agency();

        // A stray agency_features row for a switchboard key must NOT win — the
        // existing store is authoritative (spec §7.2). matches_enabled defaults on.
        $this->override($agency, 'core-matches', false);
        $this->assertTrue(
            $this->svc()->enabled('core-matches', $agency),
            'the store adapter, not agency_features, decides a switchboard key'
        );
    }

    // ── Phase 5: FeatureSettingsController canonical saver ────────────────────

    public function test_features_page_saver_writes_module_toggles_guarded(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        // Turn a default-off module ON and a default-on module OFF; omit others.
        $this->actingAs($admin)
            ->post(route('corex.settings.features.update'), [
                'rentals' => '1',   // default off -> on
                'payroll' => '0',   // present "0" -> off (explicit)
                // (document-library omitted entirely -> left alone)
            ])
            ->assertRedirect();

        $this->assertTrue($this->svc()->enabled('rentals', $agency->fresh()));
        $this->assertDatabaseHas('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'rentals', 'enabled' => true]);
        $this->assertDatabaseHas('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'payroll', 'enabled' => false]);
        // Omitted key was never written (absent => leave alone, §6.1).
        $this->assertDatabaseMissing('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'document-library']);
    }

    public function test_features_page_saver_never_writes_switchboard_or_core_keys(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        // Even if a hostile POST includes switchboard/core keys, the saver ignores them.
        $this->actingAs($admin)
            ->post(route('corex.settings.features.update'), [
                'core-matches' => '0',   // switchboard — must be ignored here
                'properties'   => '0',   // core — must be ignored
                'rentals'      => '1',   // module — written
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'core-matches']);
        $this->assertDatabaseMissing('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'properties']);
        $this->assertDatabaseHas('agency_features', ['agency_id' => $agency->id, 'feature_key' => 'rentals', 'enabled' => true]);
    }

    public function test_features_page_saver_requires_permission(): void
    {
        $agency = $this->agency();
        // A plain agent — in a fresh test DB PermissionService allow-alls, so to
        // prove the gate we assert the ROUTE middleware carries it.
        $route = app('router')->getRoutes()->getByName('corex.settings.features.update');
        $this->assertContains('permission:agency_features.manage', $route->gatherMiddleware());
    }

    // ── 12. Config validity + backfill ───────────────────────────────────────

    public function test_registry_config_validates(): void
    {
        $this->artisan('corex:features:validate')->assertExitCode(0);
    }

    public function test_backfill_enables_default_off_module_features_but_skips_switchboard(): void
    {
        $agency = $this->agency();

        $this->artisan('agency:backfill-features')->assertExitCode(0);

        // A default-OFF module feature gets an explicit ON row (deploy hides nothing).
        $this->assertDatabaseHas('agency_features', [
            'agency_id' => $agency->id, 'feature_key' => 'rentals', 'enabled' => true,
        ]);
        // Switchboard-origin keys are skipped (owned by their existing store / Phase 2 adapter).
        $this->assertDatabaseMissing('agency_features', [
            'agency_id' => $agency->id, 'feature_key' => 'syndication-p24',
        ]);
        // Core features never get a row.
        $this->assertDatabaseMissing('agency_features', [
            'agency_id' => $agency->id, 'feature_key' => 'properties',
        ]);

        // Idempotent — a second run writes nothing new.
        $before = AgencyFeature::where('agency_id', $agency->id)->count();
        $this->artisan('agency:backfill-features')->assertExitCode(0);
        $this->assertSame($before, AgencyFeature::where('agency_id', $agency->id)->count());
    }
}
