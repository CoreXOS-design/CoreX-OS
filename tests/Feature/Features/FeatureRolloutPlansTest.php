<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\DevSetting;
use App\Models\FeatureRollout\FeatureRolloutPlan;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\FeatureRolloutService;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several rollout plans, copies, and the plan dropdown at Start. Spec: agency-feature-rollout.md §22.
 */
final class FeatureRolloutPlansTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00'));
        config(['feature-rollout.readiness' => []]);
        DevSetting::set('feature_rollout.interval_days', '2');
        DevSetting::set('feature_rollout.weekdays_only', '1');
        DevSetting::set('feature_rollout.skip_holidays', '0');
        DevSetting::set('feature_rollout.release_time', '07:00');

        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->forceFill(['is_owner' => true])->save();
        Role::clearCache();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(string $type = 'both'): Agency
    {
        $agency = Agency::create(['name' => 'Plans Test ' . uniqid(), 'slug' => 'plans-' . uniqid()]);
        app(AgencyTypeFeatureSeeder::class)->seedNewAgency($agency, $type);
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step = 1;
        $s->completed_steps = ['welcome', 'identity'];
        $s->expires_at = now()->addDays(30);
        $s->save();

        return $agency->fresh();
    }

    private function on(Agency $agency, string $key): bool
    {
        app(AgencyFeatureService::class)->forget($agency->id);

        return app(AgencyFeatureService::class)->enabled($key, $agency);
    }

    private function plan(string $name, string $type, array $waves): FeatureRolloutPlan
    {
        $plan = FeatureRolloutPlan::create(['name' => $name, 'agency_type' => $type, 'is_default' => false]);
        foreach ($waves as $i => [$title, $keys]) {
            FeatureRolloutWave::create(['plan_id' => $plan->id, 'title' => $title, 'track' => 'general', 'position' => ($i + 1) * 10, 'feature_keys' => $keys]);
        }

        return $plan;
    }

    public function test_migration_leaves_one_default_plan_and_new_waves_join_it(): void
    {
        $wave = FeatureRolloutWave::create(['title' => 'Docs', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);

        $this->assertSame(FeatureRolloutPlan::defaultId(), (int) $wave->plan_id);
        $this->assertSame(1, FeatureRolloutPlan::where('is_default', true)->count());
    }

    public function test_starting_from_a_plan_holds_only_that_plans_features_and_records_it(): void
    {
        $rentals = $this->plan('Rentals only', 'rentals', [['Documents', ['docuperfect']]]);
        $this->plan('Sales only', 'sales', [['Presenting', ['presentations']]]);
        $agency = $this->agency('both');

        $plan = app(FeatureRolloutService::class)->start($agency, ['plan_id' => $rentals->id]);

        $this->assertSame($rentals->id, (int) $plan->source_plan_id);
        $this->assertFalse($this->on($agency, 'docuperfect'), "the chosen plan's feature is held");
        $this->assertTrue($this->on($agency, 'presentations'), "another plan's feature is untouched");
    }

    public function test_with_no_choice_the_plan_matching_the_agency_type_is_used_else_the_default(): void
    {
        $sales = $this->plan('Sales only', 'sales', [['Presenting', ['presentations']]]);
        $svc = app(FeatureRolloutService::class);

        $this->assertSame($sales->id, $svc->resolvePlanId($this->agency('sales')));
        $this->assertSame(FeatureRolloutPlan::defaultId(), $svc->resolvePlanId($this->agency('rentals')), 'no rentals plan: falls back to the default');
        $this->assertSame(FeatureRolloutPlan::defaultId(), $svc->resolvePlanId($this->agency('sales'), null) === $sales->id ? FeatureRolloutPlan::defaultId() : 0);
    }

    public function test_an_archived_plan_is_never_resolved(): void
    {
        $plan = $this->plan('Old', 'sales', [['Presenting', ['presentations']]]);
        $plan->delete();

        $this->assertNotSame($plan->id, app(FeatureRolloutService::class)->resolvePlanId($this->agency('sales'), $plan->id));
    }

    public function test_copy_is_an_exact_duplicate_and_editing_it_leaves_the_original_alone(): void
    {
        $src = $this->plan('Both', 'both', [['Documents', ['docuperfect']], ['Presenting', ['presentations']]]);

        $this->actingAs($this->owner)->post(route('admin.feature-rollout-defaults.plans.store'), [
            'copy_of' => $src->id, 'name' => 'Both (copy)', 'agency_type' => 'both',
        ])->assertRedirect();

        $copy = FeatureRolloutPlan::where('name', 'Both (copy)')->firstOrFail();
        $this->assertFalse($copy->is_default);
        $this->assertSame(['Documents', 'Presenting'], FeatureRolloutWave::inPlan($copy->id)->orderBy('position')->pluck('title')->all());
        $this->assertSame([['docuperfect'], ['presentations']], FeatureRolloutWave::inPlan($copy->id)->orderBy('position')->get()->map->feature_keys->all());

        FeatureRolloutWave::inPlan($copy->id)->where('title', 'Documents')->first()->update(['title' => 'Changed']);
        $this->assertSame('Documents', FeatureRolloutWave::inPlan($src->id)->orderBy('position')->first()->title);
    }

    public function test_default_plan_cannot_be_archived_and_others_can_be_restored(): void
    {
        $default = FeatureRolloutPlan::find(FeatureRolloutPlan::defaultId());
        $other = $this->plan('Other', 'sales', [['Presenting', ['presentations']]]);

        $this->actingAs($this->owner)->delete(route('admin.feature-rollout-defaults.plans.archive', $default->id))->assertSessionHas('error');
        $this->assertNull($default->fresh()->deleted_at);

        $this->actingAs($this->owner)->delete(route('admin.feature-rollout-defaults.plans.archive', $other->id))->assertRedirect();
        $this->assertNotNull(FeatureRolloutPlan::withTrashed()->find($other->id)->deleted_at);

        $this->actingAs($this->owner)->post(route('admin.feature-rollout-defaults.plans.restore', $other->id))->assertRedirect();
        $this->assertNull(FeatureRolloutPlan::find($other->id)->deleted_at);
    }

    public function test_make_default_moves_the_flag(): void
    {
        $old = FeatureRolloutPlan::defaultId();
        $other = $this->plan('Other', 'sales', [['Presenting', ['presentations']]]);

        $this->actingAs($this->owner)->post(route('admin.feature-rollout-defaults.plans.default', $other->id))->assertRedirect();

        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse(FeatureRolloutPlan::find($old)->is_default);
        $this->assertSame(1, FeatureRolloutPlan::where('is_default', true)->count());
    }

    public function test_dev_settings_page_lists_plans_with_search_and_filter(): void
    {
        $this->plan('Rentals only', 'rentals', [['Documents', ['docuperfect']]]);
        $this->plan('Sales only', 'sales', [['Presenting', ['presentations']]]);

        $this->actingAs($this->owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->assertSee('Rentals only')->assertSee('Sales only');
        $this->actingAs($this->owner)->get(route('admin.feature-rollout-defaults.index', ['p_q' => 'Rentals']))->assertOk()
            ->assertSee('Rentals only')->assertDontSee('Sales only');
        $this->actingAs($this->owner)->get(route('admin.feature-rollout-defaults.index', ['p_type' => 'sales']))->assertOk()->assertDontSee('Rentals only');
    }

    public function test_owner_start_uses_the_chosen_plan_and_refuses_an_archived_one(): void
    {
        $agency = $this->agency('both');
        $rentals = $this->plan('Rentals only', 'rentals', [['Documents', ['docuperfect']]]);
        $gone = $this->plan('Gone', 'rentals', [['Presenting', ['presentations']]]);
        $gone->delete();
        $url = route('admin.agency-setup-progress.rollout.start', ['agency' => $agency->id]);

        $this->actingAs($this->owner)->post($url, ['interval_days' => 3, 'confirm' => '1', 'plan_id' => $gone->id])->assertSessionHasErrors('plan_id');
        $this->assertNull(app(FeatureRolloutService::class)->forAgency($agency->id));

        $this->actingAs($this->owner)->post($url, ['interval_days' => 3, 'confirm' => '1', 'plan_id' => $rentals->id])->assertRedirect();
        $this->assertSame($rentals->id, (int) app(FeatureRolloutService::class)->forAgency($agency->id)->source_plan_id);

        $this->actingAs($this->owner)->get(route('admin.agency-setup-progress.show', ['agency' => $agency->id]))->assertOk()->assertSee('from the "Rentals only" rollout');
    }

    public function test_start_page_offers_the_dropdown_and_previews_the_selected_plan(): void
    {
        $agency = $this->agency('both');
        $rentals = $this->plan('Rentals only', 'rentals', [['Documents', ['docuperfect']]]);
        $this->plan('Sales only', 'sales', [['Presenting', ['presentations']]]);

        $this->actingAs($this->owner)->get(route('admin.agency-setup-progress.show', ['agency' => $agency->id, 'plan' => $rentals->id]))
            ->assertOk()->assertSee('Which rollout')->assertSee('Rentals only (Rentals)')->assertSee('Sales only (Sales)');
    }

    public function test_non_owner_cannot_touch_plans(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $this->actingAs($user)->post(route('admin.feature-rollout-defaults.plans.store'), ['name' => 'X', 'agency_type' => 'any'])->assertForbidden();
    }
}
