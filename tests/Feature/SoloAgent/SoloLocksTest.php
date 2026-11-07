<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 2 - the locks: .ai/specs/solo-agent-accounts.md section 5.
 * Locked features are off and denied permissions refused for a single-agent account; a normal agency is untouched.
 */
class SoloLocksTest extends TestCase
{
    use RefreshDatabase;

    private function agency(string $slug, bool $solo): Agency
    {
        $agency = Agency::create(['name' => ucfirst($slug), 'slug' => $slug]);
        if ($solo) {
            $agency->forceFill(['account_kind' => Agency::ACCOUNT_KIND_SOLO])->save();
            // Creating an agency already made the feature gate work out (and remember) its answers as a normal
            // agency. The real provisioner sets account_kind AT creation; a test that flips it afterwards must
            // drop that stale answer.
            app(AgencyFeatureService::class)->forget($agency->id);
        }

        return $agency->fresh();
    }

    public function test_every_locked_feature_key_is_a_real_registry_key(): void
    {
        $registry = array_keys((array) config('corex-features'));

        foreach (config('solo-agent.locked_features') as $key) {
            $this->assertContains($key, $registry, "solo-agent.locked_features names '{$key}', which is not a feature in the registry");
        }
    }

    public function test_every_denied_permission_is_a_real_permission_key(): void
    {
        $keys = array_column((array) config('corex-permissions.permissions'), 'key');

        foreach (config('solo-agent.denied_permissions') as $key) {
            $this->assertContains($key, $keys, "solo-agent.denied_permissions names '{$key}', which is not a catalogued permission");
        }
    }

    public function test_locked_features_are_off_for_a_solo_agency_and_nothing_else_changes(): void
    {
        $solo   = $this->agency('lone-agent', true);
        $normal = $this->agency('big-agency', false);

        $svc = app(AgencyFeatureService::class);
        $soloMap   = $svc->all($solo);
        $normalMap = $svc->all($normal);

        foreach (config('solo-agent.locked_features') as $key) {
            $this->assertFalse($soloMap[$key], "'{$key}' must be off for a solo agency");
        }

        // Every feature that is not locked (and does not depend on a locked one) resolves exactly as it does for a
        // normal agency with the same settings - the lock is the only difference.
        $registry = (array) config('corex-features');
        foreach ($soloMap as $key => $on) {
            $dependsOnLocked = array_intersect($registry[$key]['depends_on'] ?? [], config('solo-agent.locked_features')) !== [];
            if (in_array($key, config('solo-agent.locked_features'), true) || $dependsOnLocked) {
                continue;
            }
            $this->assertSame($normalMap[$key], $on, "'{$key}' must resolve the same for a solo and a normal agency");
        }
    }

    public function test_a_normal_agency_is_never_locked(): void
    {
        $normal = $this->agency('big-agency', false);
        $svc    = app(AgencyFeatureService::class);

        // Core features cannot be forced off; a normal agency's locked-list features follow its own settings (here: the
        // registry default), not the solo lock. role-manager is the one most likely to be wrongly caught.
        $default = (bool) (config('corex-features.role-manager.default') ?? true);
        $this->assertSame($default || ! empty(config('corex-features.role-manager.core')), $svc->enabled('role-manager', $normal));
    }

    public function test_denied_permissions_are_refused_for_a_solo_agent_only(): void
    {
        $solo   = $this->agency('lone-agent', true);
        $normal = $this->agency('big-agency', false);

        $soloUser   = User::factory()->create(['role' => 'admin', 'agency_id' => $solo->id,   'is_active' => true]);
        $normalUser = User::factory()->create(['role' => 'admin', 'agency_id' => $normal->id, 'is_active' => true]);

        PermissionService::clearCache();

        foreach (['access_role_manager', 'manage_users', 'agency_features.manage', 'manage_feature_rollout', 'manage_branches'] as $key) {
            $this->assertFalse(PermissionService::userHasPermission($soloUser, $key), "solo agent must be refused '{$key}'");
            $this->assertTrue(PermissionService::userHasPermission($normalUser, $key), "a normal agency admin keeps '{$key}'");
        }

        // A permission that is not denied is untouched for the solo agent.
        $this->assertTrue(PermissionService::userHasPermission($soloUser, 'access_settings'));
    }
}
