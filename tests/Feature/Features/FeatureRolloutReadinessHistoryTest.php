<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\FeatureRollout\AgencyRolloutItem;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-478 Part 5a — readiness ("set up first", spec §12.1, acceptance #14) and the rollout history
 * (§12.10: every release, skip, pause and access change, with who and when).
 */
final class FeatureRolloutReadinessHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect', 'presentations']]);
        FeatureRolloutWave::create(['title' => 'Later', 'track' => 'general', 'position' => 20, 'feature_keys' => ['compliance']]);

        // No logo, no address, no FFC / PPRA numbers: nothing is "ready" yet.
        $this->agency = Agency::create(['name' => 'Ready ' . uniqid(), 'slug' => 'ready-' . uniqid(), 'address' => null, 'logo_path' => null]);
        $now = now();
        DB::table('roles')->insert([
            ['name' => 'admin', 'label' => 'Principal', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4, 'agency_id' => $this->agency->id, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Role::clearCache();
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true, 'name' => 'Thandi Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function svc(): FeatureRolloutService
    {
        return app(FeatureRolloutService::class);
    }

    private function plan(): AgencyFeatureRollout
    {
        return $this->svc()->forAgency((int) $this->agency->id);
    }

    private function item(string $key): AgencyRolloutItem
    {
        return AgencyRolloutItem::queryWithoutAgencyScope()->where('rollout_id', $this->plan()->id)->where('feature_key', $key)->firstOrFail();
    }

    private function on(string $key): bool
    {
        app(AgencyFeatureService::class)->forget($this->agency->id);

        return app(AgencyFeatureService::class)->enabled($key, $this->agency);
    }

    private function at(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        $this->svc()->releaseDue();
    }

    // ── Readiness ──────────────────────────────────────────────────────────────

    public function test_a_feature_whose_setup_is_missing_waits_and_switches_on_once_it_is_done(): void
    {
        $this->svc()->start($this->agency);
        $this->at('2026-11-06 08:00'); // Documents goes live

        $this->assertSame(AgencyRolloutItem::STATE_AWAITING_SETUP, $this->item('docuperfect')->state);
        $this->assertFalse($this->on('docuperfect'), 'not switched on without a letterhead');
        $this->assertSame(AgencyRolloutItem::STATE_RELEASED, $this->item('presentations')->state, 'a feature with no requirements is unaffected');

        $html = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Set up first', $html);
        $this->assertStringContainsString('data-readiness="docuperfect"', $html);
        $this->assertStringContainsString(route('corex.agency-setup.step', ['step' => 'branding'], false), $html);
        $this->assertStringContainsString('Switch on anyway', $html);

        // Filled in on a Saturday: it waits for the next release day.
        $this->agency->forceFill(['logo_path' => 'logos/a.png', 'address' => '1 Main Road'])->save();
        $this->at('2026-11-07 10:00');
        $this->assertSame(AgencyRolloutItem::STATE_AWAITING_SETUP, $this->item('docuperfect')->state, 'nothing switches on at a weekend');
        $this->at('2026-11-09 07:30');
        $this->assertSame(AgencyRolloutItem::STATE_RELEASED, $this->item('docuperfect')->state);
        $this->assertTrue($this->on('docuperfect'));
    }

    public function test_switch_on_anyway_and_the_day_before_prompt_names_what_is_missing(): void
    {
        $this->svc()->start($this->agency);
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00')); // the day before "Later" (Tue 10 Nov)
        $this->at('2026-11-06 08:00');
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), [
            'action' => 'keep', 'wave_ids' => [$this->item('docuperfect')->wave_id],
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-09 08:00'));
        $html = $this->actingAs($this->admin)->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringContainsString('rollout-review-heading', $html);
        $this->assertStringContainsString('data-readiness="compliance"', $html);
        $this->assertStringContainsString('Fidelity Fund Certificate', $html);

        $this->actingAs($this->admin)->post(route('corex.feature-rollout.items.on', $this->item('docuperfect')->id))->assertSessionHas('success');
        $this->assertTrue($this->on('docuperfect'));
    }

    // ── History ────────────────────────────────────────────────────────────────

    public function test_the_history_records_each_change_with_who_and_when(): void
    {
        $this->actingAs($this->admin);
        $this->svc()->start($this->agency, [], $this->admin->id);
        $this->svc()->pause($this->plan());
        $this->svc()->resume($this->plan());
        $this->svc()->skipItem($this->item('compliance'));
        $this->svc()->updateSettings($this->plan(), ['interval_days' => 3]);
        auth()->logout();
        $this->at('2026-11-09 08:00'); // the scheduler releases Documents — "Automatic"

        $owner = $this->owner();
        $this->actingAs($owner);
        $this->svc()->addWave($this->plan(), 'Owner group', null);

        $html = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->assertOk()->getContent();
        foreach (['Gradual introduction started', 'Paused.', 'Resumed.', 'skipped — it stays off', 'Pace changed: every 3 release days',
                  '"Documents" switched on', 'Group "Owner group" added.'] as $line) {
            $this->assertStringContainsString(e($line), $html, $line);
        }
        $this->assertStringContainsString('Thandi Admin', $html);
        $this->assertStringContainsString('Automatic', $html);
        $this->assertStringContainsString('CoreX team', $html);

        $filtered = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index', ['h_kind' => 'switched_on']))->assertOk()->getContent();
        $this->assertStringContainsString(e('"Documents" switched on'), $filtered);
        $this->assertStringNotContainsString('Paused.', $filtered);

        // The owner page shows the same history.
        $this->actingAs($owner)->get(route('admin.agency-setup-progress.show', ['agency' => $this->agency->id]))->assertOk()
            ->assertSee('Gradual introduction started');
    }

    public function test_an_access_review_is_recorded_and_another_agencys_history_never_shows(): void
    {
        $this->svc()->start($this->agency);
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00'));
        $waveId = $this->item('docuperfect')->wave_id;
        $this->actingAs($this->admin)->postJson(route('api.v1.feature-rollout.access-review'), [
            'action' => 'save', 'wave_ids' => [$waveId], 'access' => ['presentations' => ['agent' => true, 'admin' => true]],
        ])->assertOk();
        // Saving wrote this agency's first real grants, so the permission system now enforces them: give the
        // admin the rollout permission a real agency admin holds.
        DB::table('role_permissions')->insert(['role' => 'admin', 'permission_key' => 'manage_feature_rollout', 'agency_id' => $this->agency->id, 'created_at' => now(), 'updated_at' => now()]);
        PermissionService::clearCache();

        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $this->svc()->start($other);
        $this->svc()->pause($this->svc()->forAgency((int) $other->id));

        $html = $this->actingAs($this->admin)->get(route('corex.feature-rollout.index', ['h_kind' => 'access']))->assertOk()->getContent();
        $this->assertStringContainsString('Checked who gets', $html);
        $this->assertStringContainsString('gave ' . e(config('corex-features.presentations.label')) . ' to Agent', $html);
        $this->assertStringNotContainsString('Paused.', $this->actingAs($this->admin)->get(route('corex.feature-rollout.index'))->getContent(), 'the other agency paused, not this one');
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'agency_id' => null], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->forceFill(['is_owner' => true])->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }
}
