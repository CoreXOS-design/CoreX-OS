<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
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
 * AT-478 §18 — the "Admin decides" group: switches on after every other group, for the agency's admins only;
 * agents are told nothing until an admin gives their role access, and then get the "New in CoreX" pop-up.
 * Runs on the REAL permission system (agency roles + seeded grants), like FeatureRolloutPopupsTest.
 */
final class FeatureRolloutAdminDecidesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature-rollout.readiness' => []]); // readiness has its own tests
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutWave::create(['title' => 'Extras', 'track' => 'admin', 'position' => 5, 'feature_keys' => ['presentations', 'title-deeds']]);

        $this->agency = Agency::create(['name' => 'Admin decides ' . uniqid(), 'slug' => 'admin-decides-' . uniqid()]);
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

    private function page(User $user): string
    {
        PermissionService::clearCache();
        app(AgencyFeatureService::class)->forget($this->agency->id);

        return $this->actingAs($user)->get(route('agent.portal'))->assertOk()->getContent();
    }

    private function releaseAt(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        app(FeatureRolloutService::class)->releaseDue();
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    /** The admin answers the ordinary access check for the Documents group, so only "Admin decides" is left. */
    private function reviewDocuments(AgencyFeatureRollout $plan): void
    {
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$this->wave($plan, 'Documents')->id]])->assertOk();
    }

    private function can(User $user, string $feature): bool
    {
        PermissionService::clearCache();
        app(AgencyFeatureService::class)->forget($this->agency->id);

        return app(FeatureRolloutAccess::class)->userCanAccess($user->fresh(), $feature, $this->agency->fresh());
    }

    public function test_it_comes_after_every_other_group_even_when_listed_first_and_is_hidden_from_agents_until_then(): void
    {
        $plan = $this->start();
        $docs = $this->wave($plan, 'Documents');
        $extras = $this->wave($plan, 'Extras');
        $this->assertSame(AgencyRolloutWave::LANE_ADMIN, $extras->lane);
        $this->assertSame('2026-11-06', $docs->scheduled_for->toDateString());
        $this->assertSame('2026-11-10', $extras->scheduled_for->toDateString(), 'two release days after the last other group');
        $this->assertFalse(app(AgencyFeatureService::class)->enabled('presentations', $this->agency->fresh()), 'held from day one');

        // Agents are not told about it; the admin is.
        $agentView = app(FeatureRolloutUpdates::class)->forUser($this->agent);
        $this->assertSame(['Documents'], collect($agentView['coming'])->map(fn ($c) => $c['wave']->title)->all());
        $adminView = app(FeatureRolloutUpdates::class)->forUser($this->admin);
        $this->assertContains('Extras', collect($adminView['coming'])->map(fn ($c) => $c['wave']->title)->all());
        $item = AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $plan->id)->where('feature_key', 'presentations')->first();
        $this->expectException(\DomainException::class);
        app(FeatureRolloutUpdates::class)->requestEarly($this->agent, (int) $item->id);
    }

    public function test_switch_the_next_group_on_never_reaches_it_while_another_group_is_still_to_come(): void
    {
        $plan = $this->start();
        app(FeatureRolloutService::class)->releaseNext($plan, null);
        $this->assertNotNull($this->wave($plan, 'Documents')->released_at);
        $this->assertNull($this->wave($plan, 'Extras')->released_at);
        $this->reviewDocuments($plan);

        // The day before it there is no admin review or notice: nobody else is affected.
        Carbon::setTestNow(Carbon::parse($this->wave($plan, 'Extras')->scheduled_for->toDateString() . ' 06:00')->subDay());
        $this->assertStringNotContainsString('rollout-review-heading', $this->page($this->admin));
        $this->assertSame(0, app(FeatureRolloutUpdates::class)->notifyDayBefore(now()->setTime(8, 0)));
    }

    public function test_it_switches_on_for_the_admins_only_and_the_admin_is_asked_who_else_gets_it(): void
    {
        $plan = $this->start();
        $this->assertTrue(app(FeatureRolloutAccess::class)->grid($this->agency->id, ['presentations'])['presentations']['agent'], 'agents normally get presentations');
        $this->releaseAt('2026-11-06 08:00');
        $this->reviewDocuments($plan);
        $this->releaseAt('2026-11-10 08:00');
        $this->assertNotNull($this->wave($plan, 'Extras')->released_at);
        $this->assertSame(AgencyFeatureRollout::STATUS_COMPLETED, $plan->fresh()->status);

        $this->assertTrue($this->can($this->admin, 'presentations'));
        $this->assertFalse($this->can($this->agent, 'presentations'), 'agents do not see it');
        $this->assertStringNotContainsString('Extras', $this->page($this->agent));

        $html = $this->page($this->admin);
        $this->assertStringContainsString('Ready for you — who else should see these?', $html);
        $this->assertStringContainsString('data-rollout-access="presentations|agent"', $html);
        $this->assertStringContainsString('Keep for admins only', $html);
        $this->assertStringNotContainsString('Hold this group', $html);

        $wave = $this->wave($plan, 'Extras');
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$wave->id]])->assertOk();
        $html = $this->page($this->admin);
        $this->assertStringContainsString('rollout-release-heading', $html, 'then the admin gets the New in CoreX pop-up for it');
        $this->assertFalse($this->can($this->agent, 'presentations'), 'Keep for admins only gives nothing away');
    }

    public function test_an_agent_given_it_later_gets_the_new_in_corex_popup_once_however_access_was_given(): void
    {
        $plan = $this->start();
        $this->releaseAt('2026-11-06 08:00');
        $this->reviewDocuments($plan);
        $this->releaseAt('2026-11-10 08:00');
        $wave = $this->wave($plan, 'Extras');
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$wave->id]])->assertOk();

        // Weeks later, from the Feature rollout page.
        Carbon::setTestNow(Carbon::parse('2027-01-20 09:00'));
        $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->assertOk()
            ->assertSee('Who else sees the "Admin decides" features', false)->assertSee('data-admin-access-box="presentations|agent"', false);
        $this->actingAs($this->admin)->post(route('corex.feature-rollout.access'), [
            'access' => ['presentations' => ['agent' => '1'], 'docuperfect' => ['agent' => '0']], // docuperfect is not "Admin decides" — ignored
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->can($this->agent, 'presentations'));
        $this->assertTrue($this->can($this->agent, 'docuperfect'), 'a feature outside the group is never touched here');

        $html = $this->page($this->agent);
        $this->assertStringContainsString('rollout-release-heading', $html);
        $this->assertStringContainsString(e(config('corex-features.presentations.label')), $html);
        $this->assertStringNotContainsString(e(config('corex-features.title-deeds.label')), $html, 'only what they were given');

        $this->actingAs($this->agent)->postJson(route('api.v1.feature-rollout.announcements.seen'), ['wave_ids' => [$wave->id]])->assertOk();
        $this->assertStringNotContainsString('rollout-release-heading', $this->page($this->agent), 'once');

        // Given the second one later through the Role Manager's own write — told about that one, once.
        app(FeatureRolloutAccess::class)->setRoleAccess($this->agency->id, 'agent', 'title-deeds', true);
        $html = $this->page($this->agent);
        $this->assertStringContainsString(e(config('corex-features.title-deeds.label')), $html);
        $this->assertStringNotContainsString(e(config('corex-features.presentations.label')), $html);
    }

    public function test_an_agent_cannot_save_who_sees_these(): void
    {
        $this->start();
        $this->actingAs($this->agent)->post(route('corex.feature-rollout.access'), ['access' => ['presentations' => ['agent' => '1']]])->assertForbidden();
    }

    public function test_the_corex_team_can_add_an_admin_decides_group_for_one_agency_and_side_by_side_leaves_it_last(): void
    {
        $this->agency->forceFill(['agency_type' => 'both'])->save();
        $plan = $this->start();
        $service = app(FeatureRolloutService::class);
        $added = $service->addWave($plan, 'Later for admins', null, AgencyRolloutWave::LANE_ADMIN);
        $this->assertSame('admin', $added->track);
        $this->assertTrue($added->isAdminDecides());

        $service->updateSettings($plan->fresh(), ['side_by_side' => true]);
        $service->updateSettings($plan->fresh(), ['side_by_side' => false]);
        $this->assertSame(AgencyRolloutWave::LANE_ADMIN, $this->wave($plan, 'Extras')->lane, 'side by side never moves it');
        $this->assertGreaterThan($this->wave($plan, 'Documents')->scheduled_for, $this->wave($plan, 'Extras')->scheduled_for);
    }
}
