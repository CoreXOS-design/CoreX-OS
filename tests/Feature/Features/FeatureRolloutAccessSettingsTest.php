<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutAccess;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-478 §21 — the access review shows and writes every Role Manager setting a feature owns
 * (spec agency-feature-rollout.md §21). Runs on the real permission system, like FeatureRolloutPopupsTest.
 */
final class FeatureRolloutAccessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature-rollout.readiness' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday: the plan starts, Documents lands Fri 6 Nov
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect', 'core-matches']]);

        $this->agency = Agency::create(['name' => 'Settings ' . uniqid(), 'slug' => 'settings-' . uniqid()]);
        $now = now();
        DB::table('roles')->insert([
            ['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'admin', 'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'viewer', 'label' => 'Viewer', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 5, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main ' . uniqid()]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        $this->plan = app(FeatureRolloutService::class)->start($this->agency);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00')); // Thursday: the day before, so the admin's review is due
    }

    private AgencyFeatureRollout $plan;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function access(): FeatureRolloutAccess
    {
        return app(FeatureRolloutAccess::class);
    }

    private function wave(): \App\Models\FeatureRollout\AgencyRolloutWave
    {
        return app(FeatureRolloutService::class)->wavesOf($this->plan)->firstWhere('title', 'Documents');
    }

    /** @return array<string,?string> permission key => scope, for the role's live grants */
    private function grants(string $role): array
    {
        return RolePermission::where('agency_id', $this->agency->id)->where('role', $role)->pluck('scope', 'permission_key')->all();
    }

    private function review(array $body)
    {
        return $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), $body + ['wave_ids' => [$this->wave()->id]]);
    }

    /** A grant every test starts from: agent can open and use Core Matches (all its permissions), scope Own. */
    private function giveAgentCoreMatches(): void
    {
        foreach ($this->access()->ownedKeys('core-matches') as $key) {
            RolePermission::updateOrCreate(
                ['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => $key],
                ['scope' => str_ends_with($key, '.view') ? 'own' : null],
            );
        }
        PermissionService::clearCache();
    }

    public function test_the_pop_up_lists_every_setting_a_feature_owns_like_the_role_manager(): void
    {
        $settings = $this->access()->settingsFor((int) $this->agency->id, ['core-matches', 'docuperfect']);

        $this->assertSame(['admin', 'agent', 'viewer'], array_column($settings['roles'], 'name'));
        $module = $settings['features']['core-matches']['modules'][0];
        $this->assertSame('Core Matches', $module['label']);
        $this->assertSame('core_matches.view', $module['view']);
        $this->assertSame('scope', $module['mode']);
        $this->assertContains('create', array_column($module['standard'], 'action'));
        $this->assertNotEmpty($module['other'], 'extra actions are listed too');
        // Every key the feature owns is in the state the browser starts from, for every role.
        foreach ($this->access()->ownedKeys('core-matches') as $key) {
            $this->assertSame(['admin', 'agent', 'viewer'], array_keys($settings['granted'][$key]), $key);
        }
        $this->assertArrayHasKey('core_matches.view', $settings['scope']);
        $this->assertStringStartsWith('Default', $settings['defaults']['core_matches.view']);

        $html = $this->actingAs($this->admin)->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringContainsString('Editing permissions for', $html);
        $this->assertStringContainsString('data-rollout-who="core-matches"', $html);
        $this->assertStringContainsString('data-rollout-perm="core_matches.create"', $html);
        $this->assertStringContainsString('data-rollout-scope="core_matches.view"', $html);
        $this->assertStringContainsString('data-rollout-access="core-matches|agent"', $html);
    }

    public function test_keys_that_end_in_the_same_word_are_all_listed(): void
    {
        // auctions.view / auctions.reserve.view and shared_drive.folders.create / shared_drive.drives.create used to
        // overwrite each other, so one of each pair never reached the pop-up.
        $settings = $this->access()->settingsFor((int) $this->agency->id, ['auctions', 'shared-drive']);

        foreach (['auctions', 'shared-drive'] as $feature) {
            $shown = [];
            foreach ($settings['features'][$feature]['modules'] as $m) {
                $shown = array_merge($shown, array_column($m['access'], 'key'), array_column($m['standard'], 'key'), array_column($m['other'], 'key'), $m['view'] ? [$m['view']] : []);
            }
            $owned = array_values(array_intersect($this->access()->ownedKeys($feature), \App\Models\CoreXPermission::pluck('key')->all()));
            $this->assertSame([], array_values(array_diff($owned, $shown)), "{$feature}: every owned permission is shown");
            $this->assertSame(count($shown), count(array_unique($shown)), "{$feature}: none is shown twice");
        }
    }

    public function test_changing_one_action_changes_exactly_that_and_nothing_else(): void
    {
        $this->giveAgentCoreMatches();
        $before = $this->grants('agent');
        $adminBefore = $this->grants('admin');

        $this->review(['action' => 'save', 'settings' => ['agent' => ['core_matches.create' => false]]])->assertOk();

        $after = $this->grants('agent');
        $this->assertSame(['core_matches.create'], array_keys(array_diff_key($before, $after)));
        $this->assertSame([], array_diff_key($after, $before));
        $this->assertSame($adminBefore, $this->grants('admin'), 'another role is untouched');
        $this->assertSoftDeleted('role_permissions', ['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'core_matches.create']);

        // Giving it back restores the same row.
        $this->review(['action' => 'keep']); // reviewed; a second review needs a fresh wave state
        $this->wave()->forceFill(['access_reviewed_at' => null])->save();
        $this->review(['action' => 'save', 'settings' => ['agent' => ['core_matches.create' => true]]])->assertOk();
        $this->assertSame($before, $this->grants('agent'));
        $this->assertSame(1, RolePermission::withTrashed()->where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'core_matches.create')->count());
    }

    public function test_data_scope_none_removes_view_and_the_standard_actions_but_not_other_actions(): void
    {
        $this->giveAgentCoreMatches();

        $this->review(['action' => 'save', 'scopes' => ['agent' => ['core_matches.view' => 'none']]])->assertOk();

        $after = $this->grants('agent');
        foreach (['core_matches.view', 'core_matches.create', 'core_matches.edit', 'core_matches.archive'] as $key) {
            $this->assertArrayNotHasKey($key, $after, "{$key} goes with Data Scope None");
        }
        $others = array_diff($this->access()->ownedKeys('core-matches'), ['core_matches.view', 'core_matches.create', 'core_matches.edit', 'core_matches.archive']);
        foreach ($others as $key) {
            $this->assertArrayHasKey($key, $after, "{$key} is another action and stays");
        }
    }

    public function test_changing_data_scope_stores_it_on_the_view_row_only(): void
    {
        $this->giveAgentCoreMatches();

        $this->review(['action' => 'save', 'scopes' => ['agent' => ['core_matches.view' => 'branch']]])->assertOk();

        $after = $this->grants('agent');
        $this->assertSame('branch', $after['core_matches.view']);
        $this->assertNull($after['core_matches.create'], 'scope lives on .view keys only');
    }

    public function test_ticking_an_action_on_a_role_with_no_view_gives_it_a_view_at_own(): void
    {
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'viewer')->whereIn('permission_key', $this->access()->ownedKeys('core-matches'))->delete();
        PermissionService::clearCache();
        $this->assertArrayNotHasKey('core_matches.view', $this->grants('viewer'));

        $this->review(['action' => 'save', 'settings' => ['viewer' => ['core_matches.create' => true]]])->assertOk();

        $after = $this->grants('viewer');
        $this->assertArrayHasKey('core_matches.create', $after);
        $this->assertSame('own', $after['core_matches.view']);
    }

    public function test_nothing_outside_the_reviewed_features_and_roles_is_ever_written(): void
    {
        $this->giveAgentCoreMatches();
        $owned = $this->access()->ownedKeys('core-matches');
        $agentBefore = $this->grants('agent');
        $viewerBefore = $this->grants('viewer');
        $outsideKey = 'billing.view'; // a permission of a feature that is not in this review
        $this->assertNotContains($outsideKey, $owned);
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', $outsideKey)->delete();
        $agentBefore = $this->grants('agent');

        $this->review(['action' => 'save', 'settings' => [
            'agent'       => [$outsideKey => true, 'core_matches.create' => false],
            'super_admin' => ['core_matches.create' => true],   // not an editable role
            'nobody'      => ['core_matches.create' => true],   // not a role at all
        ]])->assertOk();

        $this->assertSame(array_diff_key($agentBefore, ['core_matches.create' => 1]), $this->grants('agent'), 'only the owned key changed; the outside key was ignored');
        $this->assertSame($viewerBefore, $this->grants('viewer'));
        $this->assertSame(0, RolePermission::where('agency_id', $this->agency->id)->whereIn('role', ['super_admin', 'nobody'])->where('permission_key', 'core_matches.create')->count());
    }

    public function test_keep_as_is_and_hold_and_later_write_nothing(): void
    {
        $this->giveAgentCoreMatches();
        $before = $this->grants('agent');
        $payload = ['settings' => ['agent' => ['core_matches.create' => false]], 'scopes' => ['agent' => ['core_matches.view' => 'none']]];

        $this->review(['action' => 'later'] + $payload)->assertOk();
        $this->review(['action' => 'keep'] + $payload)->assertOk();

        $this->assertSame($before, $this->grants('agent'));
    }

    public function test_the_change_is_recorded_in_the_history_in_words(): void
    {
        $this->giveAgentCoreMatches();

        $this->review(['action' => 'save', 'settings' => ['agent' => ['core_matches.create' => false]]])->assertOk();

        $page = app(\App\Services\FeatureRollout\FeatureRolloutHistory::class)->forAgency((int) $this->agency->id, [], 1, 'page', '/x', []);
        $text = collect($page->items())->pluck('what')->implode(' | ');
        $this->assertStringContainsString('changed what Agent can do in', $text);
    }

    public function test_the_old_one_tick_per_role_request_still_works(): void
    {
        $this->review(['action' => 'save', 'access' => ['core-matches' => ['agent' => true]]])->assertOk();

        $this->assertArrayHasKey('core_matches.view', $this->grants('agent'));
    }
}
