<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Http\Controllers\CoreX\FeatureSettingsController;
use App\Http\Controllers\CoreX\RoleManagerController;
use App\Http\Middleware\CheckPermission;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Features page spec — does switching a feature OFF actually switch it off, everywhere, and ON bring it back?
 *
 * Proven here, for EVERY feature that owns permissions (not a sample):
 *   - OFF: every permission it owns is refused by the `permission:` route middleware with a 404 (the same
 *     response `feature:<key>` gives), including features switched off through a parent (`depends_on`);
 *   - ON: the same permissions pass again for a user who holds them;
 *   - Role Manager hides an OFF feature's permissions, a save never wipes their grants, a crafted post cannot
 *     change them, "copy permissions" leaves them alone, and turning the feature back on shows them again with
 *     every grant intact;
 *   - the features saver turns every module feature off and on, never touches a core feature, and the six
 *     switchboard features follow their own stores.
 *
 * Seeding mirrors RoleManagerFunctionalTest (see its NOTE ON SEEDING): without real grants PermissionService
 * falls back to allow-all and every "blocked" assertion would pass for the wrong reason.
 */
final class FeatureOffBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissionSystem();

        $this->agency = Agency::create(['name' => 'Features ' . uniqid(), 'slug' => 'feat-' . uniqid()]);
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

    // ── helpers ─────────────────────────────────────────────────────────────

    private function seedPermissionSystem(): void
    {
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
    }

    private function features(): AgencyFeatureService
    {
        return app(AgencyFeatureService::class);
    }

    private function setFeature(string $key, bool $on): void
    {
        $svc = $this->features();

        if ($svc->isSwitchboard($key)) {
            // The six switchboard features read their EXISTING store, not agency_features (spec §7.2) —
            // so switch them the way the product does.
            $store = AgencyFeatureService::SWITCHBOARD_STORES[$key];
            if ($store['type'] === 'perf') {
                PerformanceSetting::set($store['key'], $on ? 1 : 0, $this->agency->id);
            } else {
                $this->agency->forceFill([$store['key'] => $on ? 1 : 0])->save();
            }
        } else {
            AgencyFeature::withTrashed()->updateOrCreate(
                ['agency_id' => $this->agency->id, 'feature_key' => $key],
                ['enabled' => $on, 'deleted_at' => null]
            );
        }

        $svc->forget($this->agency->id);
        PermissionService::clearCache();
    }

    /** @return int|null HTTP status the permission middleware gives (200 = passed). */
    private function gate(User $user, string ...$keys): int
    {
        Auth::login($user);
        try {
            (new CheckPermission())->handle(Request::create('/x', 'GET'), fn ($r) => response('ok'), ...$keys);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    /** @return array<string,list<string>> feature => permission keys it owns (through module or key ownership) */
    private function ownedKeysByFeature(): array
    {
        $svc = $this->features();
        $out = [];
        foreach (config('corex-permissions.permissions') as $p) {
            $f = $svc->featureForPermission($p['key']);
            if ($f !== null) {
                $out[$f][] = $p['key'];
            }
        }

        return $out;
    }

    private function actAs(User $user): void
    {
        Auth::login($user);
        PermissionService::clearCache();
    }

    private function roleManagerData(): array
    {
        $this->actAs($this->admin);

        return app(RoleManagerController::class)->index()->getData();
    }

    private function visibleKeys(array $data): array
    {
        return $data['permissions']->pluck('key')->all();
    }

    // ── routes: every owned permission, every feature, off and on ───────────

    public function test_every_owned_permission_404s_when_its_feature_is_off_and_passes_when_it_is_on(): void
    {
        $owned = $this->ownedKeysByFeature();
        $this->assertNotEmpty($owned);

        // ON can only be asserted for keys the admin actually holds (principal-only keys such as
        // reveal_mailbox_credential are deliberately excluded from admin). OFF is asserted for EVERY key.
        $held = array_flip(RolePermission::where('agency_id', $this->agency->id)->where('role', 'admin')->pluck('permission_key')->all());

        foreach ($owned as $feature => $keys) {
            $this->setFeature($feature, true);
            foreach ($keys as $key) {
                if (isset($held[$key])) {
                    $this->assertSame(200, $this->gate($this->admin, $key), "{$feature} ON: admin should pass {$key}");
                }
            }

            $this->setFeature($feature, false);
            foreach ($keys as $key) {
                $this->assertSame(404, $this->gate($this->admin, $key), "{$feature} OFF: {$key} must 404");
            }

            $this->setFeature($feature, true);   // restore for the next iteration (cascades)
        }
    }

    public function test_a_child_feature_closes_when_its_parent_is_switched_off(): void
    {
        $leaveKey = 'apply_for_leave';
        $this->setFeature('payroll', true);
        $this->setFeature('leave', true);
        $this->assertSame(200, $this->gate($this->admin, $leaveKey));

        $this->setFeature('payroll', false);          // leave depends_on payroll
        $this->assertSame(404, $this->gate($this->admin, $leaveKey), 'leave must close with its parent');

        $this->setFeature('payroll', true);
        $this->assertSame(200, $this->gate($this->admin, $leaveKey), 'and reopen with it');
    }

    public function test_a_route_with_several_permissions_is_closed_only_when_all_of_them_are_blocked(): void
    {
        $this->setFeature('rentals', false);
        $this->assertSame(404, $this->gate($this->admin, 'rentals.view', 'manage_rentals'), 'both keys belong to the off feature');
        $this->assertSame(200, $this->gate($this->admin, 'rentals.view', 'access_properties'), 'one live key keeps the route open');
    }

    public function test_core_permissions_are_never_blocked_by_any_feature_switch(): void
    {
        foreach (array_keys($this->ownedKeysByFeature()) as $feature) {
            $this->setFeature($feature, false);
        }

        foreach (['access_properties', 'access_contacts', 'view_dashboard', 'access_role_manager', 'access_settings', 'access_my_portal'] as $key) {
            $this->assertSame(200, $this->gate($this->admin, $key), "{$key} is core and must stay open with every feature off");
        }
        $this->assertSame(200, $this->gate($this->admin, 'access_branch_assignments'), 'branch administration stays open with Agency Tracker off');
    }

    public function test_feature_off_does_not_grant_or_remove_anything_it_only_blocks(): void
    {
        $before = RolePermission::where('agency_id', $this->agency->id)->count();

        $this->setFeature('rentals', false);
        $this->setFeature('rentals', true);

        $this->assertSame($before, RolePermission::where('agency_id', $this->agency->id)->count(), 'no grant rows added or removed');
        $this->assertSame(403, $this->gate($this->agent, 'leases.cancel'), 'a feature being ON never grants a permission the role lacks');
    }

    // ── Role Manager ────────────────────────────────────────────────────────

    public function test_role_manager_hides_a_switched_off_features_permissions_and_names_the_feature(): void
    {
        $rentalKeys = $this->ownedKeysByFeature()['rentals'];

        $this->setFeature('rentals', true);
        $on = $this->roleManagerData();
        foreach ($rentalKeys as $k) {
            $this->assertContains($k, $this->visibleKeys($on), "rentals ON: {$k} listed");
        }
        $this->assertArrayNotHasKey('rentals', $on['hiddenFeatures'], 'rentals is on, so it is not named as hidden');

        $this->setFeature('rentals', false);
        $off = $this->roleManagerData();
        foreach ($rentalKeys as $k) {
            $this->assertNotContains($k, $this->visibleKeys($off), "rentals OFF: {$k} hidden");
        }
        $this->assertArrayHasKey('rentals', $off['hiddenFeatures']);
        $this->assertContains('access_properties', $this->visibleKeys($off), 'core permissions stay listed');
    }

    public function test_saving_a_role_keeps_hidden_grants_and_ignores_crafted_posts_for_them(): void
    {
        $role = 'agent';
        $agencyId = $this->agency->id;
        $rentalKey = 'rentals.view';

        // Give the agent a rentals grant while rentals is ON, then switch rentals off.
        RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $rentalKey, 'agency_id' => $agencyId], ['scope' => null]);
        $this->setFeature('rentals', false);

        $visible = RolePermission::where('role', $role)->where('agency_id', $agencyId)
            ->whereNotIn('permission_key', $this->features()->blockedPermissionKeys())->pluck('permission_key')->all();

        // Admin saves the agent role from the form: hidden keys are not on it, and a crafted extra one is posted.
        $this->actAs($this->admin);
        app(RoleManagerController::class)->savePermissions(Request::create('/x', 'POST', [
            'role'        => $role,
            'permissions' => array_fill_keys(array_merge($visible, ['leases.cancel']), '1'),   // leases.cancel = crafted
        ]));

        $this->assertTrue(
            RolePermission::where('role', $role)->where('agency_id', $agencyId)->where('permission_key', $rentalKey)->exists(),
            'the hidden grant must survive a save of the role'
        );
        $this->assertFalse(
            RolePermission::where('role', $role)->where('agency_id', $agencyId)->where('permission_key', 'leases.cancel')->exists(),
            'a crafted post cannot grant a frozen permission'
        );

        // Turn rentals back on: the original grant is there, and Role Manager shows it again.
        $this->setFeature('rentals', true);
        $this->assertSame(200, $this->gate($this->agent, $rentalKey), 'access returns with the feature, grant intact');
        $this->assertContains($rentalKey, $this->visibleKeys($this->roleManagerData()));
    }

    public function test_copy_permissions_leaves_a_targets_frozen_grants_alone(): void
    {
        $agencyId = $this->agency->id;
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rentals.view', 'agency_id' => $agencyId], ['scope' => null]);
        RolePermission::where('role', 'admin')->where('agency_id', $agencyId)->where('permission_key', 'rentals.view')->forceDelete();
        $this->setFeature('rentals', false);

        $this->actAs($this->admin);
        app(RoleManagerController::class)->copyPermissions(Request::create('/x', 'POST', [
            'source_role' => 'admin', 'target_roles' => ['agent'],
        ]));

        $this->assertTrue(
            RolePermission::where('role', 'agent')->where('agency_id', $agencyId)->where('permission_key', 'rentals.view')->exists(),
            "copying admin's (visible) permissions must not strip the agent's frozen rentals grant"
        );
    }

    // ── the saver, and the switchboard six ──────────────────────────────────

    public function test_the_features_saver_switches_every_module_feature_off_and_on_and_never_touches_core(): void
    {
        $svc = $this->features();
        $this->actAs($this->admin);
        $module = $svc->moduleFeatureKeys();
        $this->assertNotEmpty($module);

        $post = fn (string $value) => app(FeatureSettingsController::class)->update(Request::create('/x', 'POST',
            array_fill_keys($module, $value) + ['dashboard' => '0', 'properties' => '0']));

        $post('0');
        $svc->forget();
        foreach ($module as $key) {
            $this->assertFalse($svc->enabled($key), "{$key} should be OFF after being switched off");
        }
        $this->assertTrue($svc->enabled('dashboard'), 'a core feature cannot be switched off');
        $this->assertTrue($svc->enabled('properties'), 'a core feature cannot be switched off');

        $post('1');
        $svc->forget();
        foreach ($module as $key) {
            $this->assertTrue($svc->enabled($key), "{$key} should be ON after being switched on (parents are on too)");
        }
    }

    public function test_the_switchboard_features_follow_their_own_stores(): void
    {
        $svc = $this->features();
        $this->actAs($this->admin);   // without a user, enabled() has no agency and answers with registry defaults

        PerformanceSetting::set('marketing_enabled', 0, $this->agency->id);
        $svc->forget($this->agency->id);
        $this->assertFalse($svc->enabled('marketing'));
        $this->assertFalse($svc->enabled('ad-manager'), 'ad-manager depends on marketing');
        $this->assertSame(404, $this->gate($this->admin, 'access_ad_manager'), 'and its permissions close with it');

        PerformanceSetting::set('marketing_enabled', 1, $this->agency->id);
        $svc->forget($this->agency->id);
        $this->assertTrue($svc->enabled('marketing'));
        $this->assertSame(200, $this->gate($this->admin, 'access_ad_manager'));

        $this->agency->forceFill(['split_branches_enabled' => 1, 'website_enabled' => 1])->save();
        $svc->forget($this->agency->id);
        $this->assertTrue($svc->enabled('multi-branch'));
        $this->assertTrue($svc->enabled('public-website'));

        $this->agency->forceFill(['split_branches_enabled' => 0, 'website_enabled' => 0])->save();
        $svc->forget($this->agency->id);
        $this->assertFalse($svc->enabled('multi-branch'));
        $this->assertFalse($svc->enabled('public-website'));
    }

    public function test_one_agency_switching_a_feature_off_does_not_affect_another(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'oth-' . uniqid()]);
        $this->setFeature('rentals', false);

        $this->assertFalse($this->features()->enabled('rentals', $this->agency));
        $this->assertTrue($this->features()->enabled('rentals', $other), 'the other agency is unaffected');
    }
}
