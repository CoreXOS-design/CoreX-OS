<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutWave;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutAccess;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\FeatureRollout\FeatureTourCoverage;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-478 Part 3 — the admin access review and the "New in CoreX" release pop-up
 * (spec agency-feature-rollout.md §2.5–2.6, §3.1, §3.5a, §5.3–5.4).
 *
 * Runs on the REAL permission system: the agency has its own admin + agent roles and seeded grants
 * (with no grants at all PermissionService lets everyone through, which would prove nothing).
 */
final class FeatureRolloutPopupsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests are about timing, lanes and pop-ups; readiness (§12.1) would hold their example features
        // back for want of a logo/address and has its own tests (FeatureRolloutReadinessHistoryTest).
        config(['feature-rollout.readiness' => []]);
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect'], 'intro_text' => 'Paperwork lives here now.']);
        FeatureRolloutWave::create(['title' => 'Presenting', 'track' => 'general', 'position' => 20, 'feature_keys' => ['presentations']]);

        $this->agency = Agency::create(['name' => 'Popups ' . uniqid(), 'slug' => 'popups-' . uniqid()]);
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

    /** @return list<string> */
    private function activeKeys(string $role): array
    {
        return RolePermission::where('agency_id', $this->agency->id)->where('role', $role)->pluck('permission_key')->sort()->values()->all();
    }

    private function releaseAt(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        app(FeatureRolloutService::class)->releaseDue();
        app(AgencyFeatureService::class)->forget($this->agency->id);
    }

    // ── Admin access review ────────────────────────────────────────────────────

    public function test_the_admin_is_asked_the_release_day_before_and_nobody_else_is(): void
    {
        $plan = $this->start(); // Documents → Friday 6 Nov, so the prompt starts Thursday 5 Nov
        $this->assertSame('2026-11-06', $this->wave($plan, 'Documents')->scheduled_for->toDateString());

        $this->assertStringNotContainsString('rollout-review-heading', $this->page($this->admin), 'Wednesday: too early');

        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $html = $this->page($this->admin);
        $this->assertStringContainsString('rollout-review-heading', $html);
        $this->assertStringContainsString('Switching on Friday 6 November', $html);
        $this->assertStringContainsString('data-rollout-access="docuperfect|agent"', $html);
        $this->assertStringNotContainsString('data-rollout-access="presentations|', $html, 'only the next group');
        $this->assertStringContainsString('Hold this group', $html);

        $this->assertStringNotContainsString('rollout-review-heading', $this->page($this->agent));
    }

    public function test_saving_changes_exactly_the_ticked_feature_and_roles_and_nothing_else(): void
    {
        $plan = $this->start();
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $access = app(FeatureRolloutAccess::class);
        $docKeys = $access->ownedKeys('docuperfect');
        $agentBefore = $this->activeKeys('agent');
        $adminBefore = $this->activeKeys('admin');
        $this->assertTrue($access->grid($this->agency->id, ['docuperfect'])['docuperfect']['agent'], 'agent starts with documents');

        $wave = $this->wave($plan, 'Documents');
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), [
            'action' => 'save', 'wave_ids' => [$wave->id],
            'access' => [
                'docuperfect'   => ['agent' => false, 'admin' => true],
                'presentations' => ['agent' => false], // not in this wave — must be ignored
            ],
        ])->assertOk()->assertJson(['ok' => true, 'changed' => 1]);

        $this->assertSame(array_values(array_diff($agentBefore, $docKeys)), $this->activeKeys('agent'), 'agent lost documents, nothing else');
        $this->assertSame($adminBefore, $this->activeKeys('admin'));
        $this->assertTrue(RolePermission::onlyTrashed()->where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'access_docuperfect')->exists(), 'a soft-delete tombstone, never a hard delete');
        $this->assertNotNull($wave->fresh()->access_reviewed_at);
        $this->assertSame($this->admin->id, $wave->fresh()->access_reviewed_by);
        $this->assertStringNotContainsString('rollout-review-heading', $this->page($this->admin), 'asked once');

        // Giving it back restores exactly what the agent had.
        $access->setRoleAccess($this->agency->id, 'agent', 'docuperfect', true);
        $this->assertSame($agentBefore, $this->activeKeys('agent'));
    }

    public function test_keep_marks_reviewed_without_touching_access_and_hold_pauses_the_plan(): void
    {
        $plan = $this->start();
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $wave = $this->wave($plan, 'Documents');
        $before = RolePermission::withTrashed()->where('agency_id', $this->agency->id)->count();

        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'hold', 'wave_ids' => [$wave->id]])->assertOk();
        $this->assertSame(AgencyFeatureRollout::STATUS_PAUSED, $plan->fresh()->status);
        $this->assertNull($wave->fresh()->access_reviewed_at, 'held, not reviewed — it asks again after Resume');

        app(FeatureRolloutService::class)->resume($plan->fresh());
        $wave = $this->wave($plan->fresh(), 'Documents');
        Carbon::setTestNow(Carbon::parse($wave->scheduled_for->toDateString() . ' 06:00'));
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$wave->id]])->assertOk();
        $this->assertNotNull($wave->fresh()->access_reviewed_at);
        $this->assertSame($before, RolePermission::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    public function test_later_skips_the_prompt_for_this_session_only(): void
    {
        $plan = $this->start();
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $wave = $this->wave($plan, 'Documents');

        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'later', 'wave_ids' => [$wave->id]])->assertOk();
        $this->assertStringNotContainsString('rollout-review-heading', $this->page($this->admin));
        $this->assertNull($wave->fresh()->access_reviewed_at);

        $this->flushSession(); // next sign-in
        $this->assertStringContainsString('rollout-review-heading', $this->page($this->admin));
    }

    public function test_a_wave_released_without_a_review_is_asked_first_and_holds_back_the_admins_release_popup(): void
    {
        $plan = $this->start();
        $this->releaseAt('2026-11-06 08:00'); // admin never signed in on Thursday
        $wave = $this->wave($plan, 'Documents');
        $this->assertNotNull($wave->fresh()->released_at, 'went live on schedule with current access');

        $html = $this->page($this->admin);
        $this->assertStringContainsString('These went live on Friday 6 November', $html);
        $this->assertStringNotContainsString('Hold this group', $html, 'a live group cannot be held');
        $this->assertStringNotContainsString('rollout-release-heading', $html, 'review comes before the announcement');

        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$wave->id]])->assertOk();
        $this->assertStringContainsString('rollout-release-heading', $this->page($this->admin), 'then the announcement');
    }

    // ── Release pop-up ─────────────────────────────────────────────────────────

    public function test_a_user_with_access_sees_the_release_popup_once_with_its_tour(): void
    {
        $plan = $this->start();
        $this->releaseAt('2026-11-06 08:00');
        $wave = $this->wave($plan, 'Documents');

        $html = $this->page($this->agent);
        $this->assertStringContainsString('rollout-release-heading', $html);
        $this->assertStringContainsString('Paperwork lives here now.', $html);
        $label = config('corex-features.docuperfect.label');
        $this->assertStringContainsString(e($label), $html);
        if ((app(FeatureTourCoverage::class)->toursByFeature()['docuperfect'] ?? []) !== []) {
            $this->assertStringContainsString('Take the tour', $html);
            $this->assertStringContainsString('?tour=', $html);
        }

        $this->actingAs($this->agent)->postJson(route('api.v1.feature-rollout.announcements.seen'), ['wave_ids' => [$wave->id]])->assertOk();
        $this->assertStringNotContainsString('rollout-release-heading', $this->page($this->agent), 'once');
    }

    public function test_a_user_without_access_sees_nothing_and_a_user_who_joined_later_is_not_told(): void
    {
        $plan = $this->start();
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), [
            'action' => 'save', 'wave_ids' => [$this->wave($plan, 'Documents')->id], 'access' => ['docuperfect' => ['agent' => false]],
        ])->assertOk();
        $this->releaseAt('2026-11-06 08:00');

        $this->assertStringNotContainsString('rollout-release-heading', $this->page($this->agent), 'agent has no access to documents');
        $this->assertStringContainsString('rollout-release-heading', $this->page($this->admin));

        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00'));
        $newcomer = $this->user('admin');
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.announcements.seen'), ['wave_ids' => [$this->wave($plan, 'Documents')->id]]);
        $this->assertStringNotContainsString('rollout-release-heading', $this->page($newcomer), 'joined after it went live');
    }

    // ── Scoping ────────────────────────────────────────────────────────────────

    public function test_another_agencys_waves_cannot_be_reviewed_or_marked_seen_and_agents_cannot_review(): void
    {
        $this->start();
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $otherPlan = app(FeatureRolloutService::class)->start($other);
        $otherWave = $this->wave($otherPlan, 'Documents');
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));

        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), [
            'action' => 'keep', 'wave_ids' => [$otherWave->id],
        ])->assertOk()->assertJson(['changed' => 0]);
        $this->assertNull($otherWave->fresh()->access_reviewed_at);

        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.announcements.seen'), ['wave_ids' => [$otherWave->id]])->assertOk();
        $this->assertDatabaseMissing('rollout_announcement_views', ['wave_id' => $otherWave->id]);

        $this->assertContains(
            $this->actingAs($this->agent)->postJson(route('api.v1.feature-rollout.access-review'), ['action' => 'keep', 'wave_ids' => [$otherWave->id]])->status(),
            [403, 404]
        );
    }

    public function test_an_agency_not_on_gradual_rollout_sees_no_popup(): void
    {
        $html = $this->page($this->admin);
        $this->assertStringNotContainsString('rollout-review-heading', $html);
        $this->assertStringNotContainsString('rollout-release-heading', $html);
    }
}
