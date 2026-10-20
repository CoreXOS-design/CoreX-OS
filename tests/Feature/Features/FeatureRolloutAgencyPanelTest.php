<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-478 Part 2 — the onboarding choice + Settings → Feature rollout (spec agency-feature-rollout.md §5.2).
 *
 * A fresh test DB has no role_permissions, so PermissionService allows all — a plain admin passes. The
 * permission test seeds the real permission system (same as FeatureOffBehaviourTest).
 */
final class FeatureRolloutAgencyPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00')); // Wednesday
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');
        FeatureRolloutWave::create(['title' => 'Documents', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        FeatureRolloutWave::create(['title' => 'Rentals', 'track' => 'rentals', 'position' => 20, 'feature_keys' => ['rentals']]);
        FeatureRolloutWave::create(['title' => 'Selling', 'track' => 'sales', 'position' => 30, 'feature_keys' => ['presentations', 'outreach']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Panel ' . uniqid(), 'slug' => 'panel-' . uniqid()]);
    }

    private function user(Agency $agency, string $role = 'admin'): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main ' . uniqid()]);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function setupFor(Agency $agency, bool $complete = false): AgencyOnboardingSetup
    {
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step = 1;
        $s->completed_steps = [];
        $s->expires_at = now()->addDays(30);
        if ($complete) {
            $s->completed_at = now();
        }
        $s->save();

        return $s;
    }

    private function on(Agency $agency, string $key): bool
    {
        app(AgencyFeatureService::class)->forget($agency->id);

        return app(AgencyFeatureService::class)->enabled($key, $agency);
    }

    private function plan(Agency $agency): ?AgencyFeatureRollout
    {
        return app(FeatureRolloutService::class)->forAgency((int) $agency->id);
    }

    // ── The wizard ─────────────────────────────────────────────────────────────

    public function test_the_wizard_shows_the_choice_and_everything_now_changes_nothing(): void
    {
        $agency = $this->agency();
        $this->setupFor($agency);
        $admin = $this->user($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk()->assertSee('How should CoreX switch on for your team?')->assertSee('feature_rollout_present', false);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), [
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'all',
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->plan($agency));
        $this->assertTrue($this->on($agency, 'docuperfect'));
    }

    public function test_choosing_gradual_in_the_wizard_needs_the_confirmation_then_starts_the_plan(): void
    {
        $agency = $this->agency();
        $this->setupFor($agency);
        $admin = $this->user($agency);
        $payload = ['feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_interval' => '3', 'feature_rollout_weekdays' => '1'];

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $payload)
            ->assertSessionHasErrors('feature_rollout_confirm');
        $this->assertNull($this->plan($agency), 'nothing is switched off without the confirmation');
        $this->assertTrue($this->on($agency, 'docuperfect'));

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $payload + ['feature_rollout_confirm' => '1'])
            ->assertSessionHasNoErrors();

        $plan = $this->plan($agency);
        $this->assertNotNull($plan);
        $this->assertSame(3, $plan->interval_days);
        $this->assertFalse($this->on($agency, 'docuperfect'));
        $this->assertTrue($this->on($agency, 'properties'));
    }

    public function test_the_plan_holds_only_what_the_admin_left_on_in_the_same_step(): void
    {
        $agency = $this->agency();
        $this->setupFor($agency);
        $admin = $this->user($agency);

        // Same submit: the admin switches Presentations off AND chooses gradual introduction.
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), [
            'presentations' => '0',
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_confirm' => '1',
        ])->assertSessionHasNoErrors();

        $held = AgencyRolloutItem::queryWithoutAgencyScope()->where('agency_id', $agency->id)->pluck('feature_key')->all();
        $this->assertContains('docuperfect', $held);
        $this->assertNotContains('presentations', $held, 'switched off by the admin, so the plan never switches it on');
    }

    public function test_a_wizard_step_without_the_block_leaves_the_rollout_alone(): void
    {
        $agency = $this->agency();
        $this->setupFor($agency);
        $admin = $this->user($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), [
            'feature_rollout_mode' => 'gradual', 'feature_rollout_confirm' => '1', // no _present marker
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->plan($agency));
    }

    public function test_an_agency_whose_setup_is_complete_is_not_offered_gradual_introduction(): void
    {
        $agency = $this->agency();
        $this->setupFor($agency, complete: true);
        $admin = $this->user($agency);

        $this->actingAs($admin)->get(route('corex.feature-rollout.index'))
            ->assertOk()->assertSee('Your setup is already complete')->assertDontSee('feature_rollout_present', false);

        $this->actingAs($admin)->post(route('corex.feature-rollout.settings'), [
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_confirm' => '1',
        ]);
        $this->assertNull($this->plan($agency), 'a crafted post cannot start it either');
    }

    // ── The panel ──────────────────────────────────────────────────────────────

    public function test_the_panel_shows_progress_and_its_controls_work(): void
    {
        $agency = $this->agency();
        $admin = $this->user($agency);
        $plan = app(FeatureRolloutService::class)->start($agency);

        $this->actingAs($admin)->get(route('corex.feature-rollout.index'))
            ->assertOk()->assertSee('Documents')->assertSee('Fri 6 Nov')->assertSee('0</strong> of', false);

        $this->actingAs($admin)->post(route('corex.feature-rollout.pause'))->assertSessionHas('success');
        $this->assertSame('paused', $plan->fresh()->status);
        $this->actingAs($admin)->post(route('corex.feature-rollout.resume'));
        $this->assertSame('active', $plan->fresh()->status);

        $this->actingAs($admin)->post(route('corex.feature-rollout.release-next'));
        $this->assertTrue($this->on($agency, 'docuperfect'));

        $this->actingAs($admin)->post(route('corex.feature-rollout.settings'), [
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_interval' => '1', 'feature_rollout_weekdays' => '0',
        ]);
        $plan->refresh();
        $this->assertSame(1, $plan->interval_days);
        $this->assertFalse($plan->weekdays_only);
        $this->assertSame('2026-11-05', app(FeatureRolloutService::class)->nextWavePerLane($plan)->first()->scheduled_for->toDateString());

        $this->actingAs($admin)->post(route('corex.feature-rollout.finish'));
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertTrue($this->on($agency, 'outreach'));
    }

    public function test_skip_keeps_a_feature_off_and_an_outward_feature_is_switched_on_by_the_admin(): void
    {
        $agency = $this->agency();
        $admin = $this->user($agency);
        app(FeatureRolloutService::class)->start($agency);
        $item = fn (string $k) => AgencyRolloutItem::queryWithoutAgencyScope()->where('agency_id', $agency->id)->where('feature_key', $k)->first();

        $this->actingAs($admin)->post(route('corex.feature-rollout.items.skip', $item('presentations')->id));
        $this->assertSame('dropped', $item('presentations')->state);

        // Release everything due: Documents, Rentals, Selling (outreach waits for the admin).
        foreach (['2026-11-06 08:00', '2026-11-10 08:00', '2026-11-12 08:00'] as $t) {
            Carbon::setTestNow(Carbon::parse($t));
            app(FeatureRolloutService::class)->releaseDue();
        }
        $this->assertFalse($this->on($agency, 'presentations'), 'a skipped feature is never switched on by the plan');
        $this->assertSame('awaiting_admin', $item('outreach')->state);
        $this->assertFalse($this->on($agency, 'outreach'));

        $this->actingAs($admin)->post(route('corex.feature-rollout.items.on', $item('outreach')->id));
        $this->assertTrue($this->on($agency, 'outreach'));
    }

    public function test_side_by_side_can_be_switched_on_and_off_for_a_sales_and_rentals_agency(): void
    {
        $agency = $this->agency(); // default type: both
        $admin = $this->user($agency);
        $plan = app(FeatureRolloutService::class)->start($agency);
        $lanes = fn () => app(FeatureRolloutService::class)->wavesOf($plan)->mapWithKeys(fn ($w) => [$w->title => $w->lane])->all();

        $this->assertSame(['Documents' => 1, 'Rentals' => 1, 'Selling' => 1], $lanes());

        $this->actingAs($admin)->post(route('corex.feature-rollout.settings'), [
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_side_by_side' => '1',
        ]);
        $this->assertSame(['Documents' => 1, 'Selling' => 1, 'Rentals' => 2], $lanes());

        $this->actingAs($admin)->post(route('corex.feature-rollout.settings'), [
            'feature_rollout_present' => '1', 'feature_rollout_mode' => 'gradual', 'feature_rollout_side_by_side' => '0',
        ]);
        $this->assertSame(['Documents', 'Rentals', 'Selling'], app(FeatureRolloutService::class)->wavesOf($plan)->pluck('title')->all(),
            'merged back in the default order');
    }

    public function test_one_agency_cannot_touch_another_agencys_plan(): void
    {
        $a = $this->agency();
        $b = $this->agency();
        $adminA = $this->user($a);
        app(FeatureRolloutService::class)->start($a);
        app(FeatureRolloutService::class)->start($b);
        $itemB = AgencyRolloutItem::queryWithoutAgencyScope()->where('agency_id', $b->id)->first();

        $this->actingAs($adminA)->post(route('corex.feature-rollout.items.skip', $itemB->id))->assertNotFound();
        $this->assertSame('held', $itemB->fresh()->state);

        $this->actingAs($adminA)->post(route('corex.feature-rollout.pause'));
        $this->assertSame('active', $this->plan($b)->status);
    }

    public function test_only_roles_with_the_permission_reach_the_panel_and_the_menu_links_it(): void
    {
        $now = now();
        DB::table('roles')->insert([
            ['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'admin', 'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'agent', 'label' => 'Agent', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4, 'agency_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $agency = $this->agency();
        $admin = $this->user($agency);
        $agent = $this->user($agency, 'agent');

        $this->assertTrue($admin->hasPermission('manage_feature_rollout'));
        $this->assertFalse($agent->hasPermission('manage_feature_rollout'));

        $html = $this->actingAs($admin)->get(route('corex.feature-rollout.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('corex.feature-rollout.index') . '"', $html, 'sidebar entry');
        $plan = app(FeatureRolloutService::class)->start($agency);
        $this->assertContains($this->actingAs($agent)->get(route('corex.feature-rollout.index'))->status(), [403, 404]);
        $this->assertContains($this->actingAs($agent)->post(route('corex.feature-rollout.pause'))->status(), [403, 404]);
        $this->assertSame('active', $plan->fresh()->status, 'the agent could not pause it');
    }
}
