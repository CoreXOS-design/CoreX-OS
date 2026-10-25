<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutFeatureCopy;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutAccess;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureRolloutUpdates;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-478 §19 — the fixes from the 2026-10-25 Chrome walkthrough: "Admin decides" no longer lets features through
 * to everyone, a feature with no access control cannot be "Admin decides", "New and coming" only promises what a
 * person will get, and the pop-ups carry the full explanation and video. Real permission system, like
 * FeatureRolloutAdminDecidesTest.
 */
final class FeatureRolloutWalkthroughFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature-rollout.readiness' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '1');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');

        $this->agency = Agency::create(['name' => 'Walkthrough ' . uniqid(), 'slug' => 'walkthrough-' . uniqid()]);
        $now = now();
        DB::table('roles')->insert([
            ['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'admin', 'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->admin = $this->user('admin');
        $this->agent = $this->user('agent');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main ' . uniqid()]);

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    private function grant(string $role, string $key): void
    {
        RolePermission::withTrashed()->updateOrCreate(
            ['agency_id' => $this->agency->id, 'role' => $role, 'permission_key' => $key],
            ['deleted_at' => null],
        );
        PermissionService::clearCache();
    }

    private function holds(string $role, string $key): bool
    {
        return RolePermission::where('agency_id', $this->agency->id)->where('role', $role)->where('permission_key', $key)->exists();
    }

    private function start(): AgencyFeatureRollout
    {
        $plan = app(FeatureRolloutService::class)->start($this->agency);
        app(AgencyFeatureService::class)->forget($this->agency->id);

        return $plan;
    }

    private function wave(AgencyFeatureRollout $plan, string $title): AgencyRolloutWave
    {
        return app(FeatureRolloutService::class)->wavesOf($plan)->firstWhere('title', $title);
    }

    private function releaseAt(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        app(FeatureRolloutService::class)->releaseDue();
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    private function page(User $user): string
    {
        PermissionService::clearCache();
        app(AgencyFeatureService::class)->forget($this->agency->id);

        return $this->actingAs($user)->get(route('agent.portal'))->assertOk()->getContent();
    }

    public function test_a_feature_whose_only_access_is_its_way_in_is_kept_to_the_admins_and_the_admin_is_asked(): void
    {
        // Billing owns no permission module, only its way in (billing.view) — the walkthrough's leak.
        $this->grant('agent', 'billing.view');
        $this->grant('admin', 'billing.view');
        FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutWave::create(['title' => 'Office', 'track' => 'admin', 'position' => 20, 'feature_keys' => ['billing']]);

        $access = app(FeatureRolloutAccess::class);
        $this->assertTrue($access->canBeLimited('billing'));
        $this->assertContains('billing.view', $access->ownedKeys('billing'));

        $plan = $this->start();
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$this->wave($plan, 'Docs')->id]])->assertOk();
        $this->releaseAt('2026-11-05 08:00');
        $this->releaseAt('2026-11-06 08:00');
        $this->assertNotNull($this->wave($plan, 'Office')->fresh()->released_at);

        $this->assertFalse($this->holds('agent', 'billing.view'), 'taken away from the agent');
        $this->assertTrue($this->holds('admin', 'billing.view'));
        $this->assertFalse($access->userCanAccess($this->agent->fresh(), 'billing', $this->agency->fresh()));

        $popup = $access->popupFor($this->admin->fresh());
        $this->assertSame('review_admin', $popup['kind'], 'the admin is asked who else should see it');
        $this->assertStringNotContainsString('Office', implode(' ', collect($access->announcementsFor($this->agent->fresh(), $plan->fresh()))->map(fn ($a) => $a['wave']->title)->all()));
    }

    public function test_a_feature_with_no_access_control_cannot_be_admin_decides(): void
    {
        $access = app(FeatureRolloutAccess::class);
        $this->assertFalse($access->canBeLimited('commission-management'));

        // Dev Settings refuses it.
        $this->actingAs($this->owner())->post(route('admin.feature-rollout-defaults.store'), [
            'title' => 'Office', 'track' => 'admin', 'feature_keys' => ['commission-management', 'billing'],
        ])->assertSessionHasErrors('feature_keys');
        $this->assertSame(0, FeatureRolloutWave::count());

        // An existing default that still has one: a new plan does not hold it there, and the check names it.
        FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutWave::create(['title' => 'Office', 'track' => 'admin', 'position' => 20, 'feature_keys' => ['commission-management', 'billing']]);
        $this->get(route('admin.feature-rollout-defaults.index'))->assertOk()->assertSee('data-check-not-limitable', false);

        $plan = $this->start();
        $held = AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->pluck('feature_key')->all();
        $this->assertNotContains('commission-management', $held);
        $this->assertContains('billing', $held);
        $this->assertTrue(app(AgencyFeatureService::class)->enabled('commission-management', $this->agency->fresh()), 'left on, like a feature in no wave');

        // The owner page cannot move one into the "Admin decides" group either.
        $docs = AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->where('feature_key', 'docuperfect')->first();
        $this->assertTrue(app(FeatureRolloutService::class)->isOpen($plan));
        app(FeatureRolloutService::class)->moveFeature($docs, $this->wave($plan, 'Office')); // docuperfect can be limited — allowed
        $this->assertSame($this->wave($plan, 'Office')->id, (int) $docs->fresh()->wave_id);
        $this->expectException(\DomainException::class);
        app(FeatureRolloutService::class)->addFeature($this->wave($plan, 'Office'), 'buyer-pipeline', null);
    }

    public function test_new_and_coming_only_promises_what_the_person_will_get(): void
    {
        FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect', 'presentations']]);
        $this->start();
        $entry = app(FeatureRolloutAccess::class)->entryKeys('presentations');
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->whereIn('permission_key', $entry)->delete();
        PermissionService::clearCache();

        $agentKeys = collect(app(FeatureRolloutUpdates::class)->forUser($this->agent->fresh())['coming'])->flatMap(fn ($c) => array_column($c['features'], 'key'))->all();
        $this->assertContains('docuperfect', $agentKeys);
        $this->assertNotContains('presentations', $agentKeys, 'the agent will not get it');
        $adminKeys = collect(app(FeatureRolloutUpdates::class)->forUser($this->admin->fresh())['coming'])->flatMap(fn ($c) => array_column($c['features'], 'key'))->all();
        $this->assertContains('presentations', $adminKeys, 'the rollout admin still sees everything');
    }

    public function test_the_release_pop_up_shows_the_full_explanation_video_and_links(): void
    {
        FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutFeatureCopy::create([
            'feature_key' => 'docuperfect', 'description' => "First paragraph about documents.\n\nSecond paragraph about signing.",
            'video_url' => 'https://videos.example.test/docuperfect', 'links' => [['label' => 'Read the guide', 'url' => '/corex/training']],
        ]);
        $plan = $this->start();
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$this->wave($plan, 'Docs')->id]])->assertOk();
        $this->releaseAt('2026-11-05 08:00');

        $html = $this->page($this->agent);
        $this->assertStringContainsString('rollout-release-heading', $html);
        $this->assertStringContainsString('data-rollout-explanation="docuperfect"', $html);
        $this->assertStringContainsString('Second paragraph about signing.', $html);
        $this->assertStringContainsString('data-rollout-video="docuperfect"', $html);
        $this->assertStringContainsString('https://videos.example.test/docuperfect', $html);
        $this->assertStringContainsString('Read the guide', $html);
    }

    public function test_the_admin_review_shows_the_default_full_explanation(): void
    {
        FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        $this->start();

        $html = $this->page($this->admin);
        $this->assertStringContainsString('rollout-review-heading', $html);
        $this->assertStringContainsString('data-rollout-access="docuperfect|agent"', $html);
        $first = \App\Services\FeatureRollout\FeatureRolloutCopy::paragraphs((string) config('feature-rollout-copy.descriptions.docuperfect'))[0];
        $this->assertStringContainsString(e($first), $html);
    }
}
