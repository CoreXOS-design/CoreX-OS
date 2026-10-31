<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\FeatureTourCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-478 — Dev Settings → Feature rollout (spec agency-feature-rollout.md §5.1, §12.11).
 */
final class FeatureRolloutDefaultsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    private function agencyAdmin(): User
    {
        $agency = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);

        return User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => true]);
    }

    public function test_owner_sees_the_page_and_it_is_linked_from_dev_settings(): void
    {
        $owner = $this->owner();
        FeatureRolloutWave::create(['title' => 'Documents first', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);

        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))
            ->assertOk()->assertSee('Documents first')->assertSee('How often')->assertSee('Features with no guided tour');

        $this->actingAs($owner)->get(route('admin.dev-settings.index'))
            ->assertOk()->assertSee(route('admin.feature-rollout-defaults.index'), false);
    }

    public function test_a_non_owner_is_refused_everywhere(): void
    {
        $admin = $this->agencyAdmin();
        $wave = FeatureRolloutWave::create(['title' => 'W', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);

        $this->actingAs($admin)->get(route('admin.feature-rollout-defaults.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.feature-rollout-defaults.store'), ['title' => 'X', 'track' => 'general', 'feature_keys' => ['docuperfect']])->assertForbidden();
        $this->actingAs($admin)->put(route('admin.feature-rollout-defaults.pace'), ['interval_days' => 1, 'holiday_country' => 'ZA', 'release_time' => '07:00'])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.feature-rollout-defaults.destroy', $wave->id))->assertForbidden();
        $this->assertSame(1, FeatureRolloutWave::count());
    }

    public function test_add_edit_move_archive_and_restore_a_wave(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.store'), [
            'title' => 'Deals', 'track' => 'general', 'intro_text' => 'Record every sale.', 'feature_keys' => ['docuperfect'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.store'), [
            'title' => 'Rentals', 'track' => 'rentals', 'feature_keys' => ['rentals'],
        ])->assertSessionHasNoErrors();
        [$a, $b] = FeatureRolloutWave::orderBy('position')->get()->all();

        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.update', $a->id), [
            'title' => 'Deals and documents', 'track' => 'general', 'feature_keys' => ['docuperfect', 'presentations'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['docuperfect', 'presentations'], $a->fresh()->feature_keys);

        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.move', $b->id), ['direction' => 'up']);
        $this->assertSame(['Rentals', 'Deals and documents'], FeatureRolloutWave::orderBy('position')->pluck('title')->all());

        $this->actingAs($owner)->delete(route('admin.feature-rollout-defaults.destroy', $b->id));
        $this->assertSoftDeleted('feature_rollout_waves', ['id' => $b->id]);
        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.restore', $b->id));
        $this->assertNotSoftDeleted('feature_rollout_waves', ['id' => $b->id]);
    }

    public function test_a_wave_needs_a_feature_and_may_not_hold_core_or_opt_in_features(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.store'), ['title' => 'Empty', 'track' => 'general'])
            ->assertSessionHasErrors('feature_keys');
        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.store'), ['title' => 'Core', 'track' => 'general', 'feature_keys' => ['properties']])
            ->assertSessionHasErrors('feature_keys.0');
        $this->actingAs($owner)->post(route('admin.feature-rollout-defaults.store'), ['title' => 'Payroll', 'track' => 'general', 'feature_keys' => ['payroll']])
            ->assertSessionHasErrors('feature_keys.0');
        $this->assertSame(0, FeatureRolloutWave::count());
    }

    public function test_pace_is_saved_including_switching_weekdays_and_holidays_off(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.pace'), [
            'interval_days' => 1, 'holiday_country' => 'za', 'release_time' => '08:30',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1', DevSetting::get('feature_rollout.interval_days'));
        $this->assertFalse(DevSetting::bool('feature_rollout.weekdays_only', true));
        $this->assertFalse(DevSetting::bool('feature_rollout.skip_holidays', true));
        $this->assertSame('ZA', DevSetting::get('feature_rollout.holiday_country'));
        $this->assertSame('08:30', DevSetting::get('feature_rollout.release_time'));

        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.pace'), [
            'interval_days' => 99, 'holiday_country' => 'ZA', 'release_time' => '08:30',
        ])->assertSessionHasErrors('interval_days');
    }

    public function test_tour_gap_report_lists_exactly_the_waved_features_without_a_tour(): void
    {
        $owner = $this->owner();
        $covered = array_keys(app(FeatureTourCoverage::class)->toursByFeature());
        $this->assertContains('docuperfect', $covered, 'DocuPerfect has tours (defs/docuperfect.php)');

        // Every waveable feature has a tour today (see the test below), so take one away for this run.
        $noTour = 'presentations';
        $real = app(FeatureTourCoverage::class)->toursByFeature();
        unset($real[$noTour]);
        $this->app->instance(FeatureTourCoverage::class, \Mockery::mock(FeatureTourCoverage::class, ['toursByFeature' => $real]));

        FeatureRolloutWave::create(['title' => 'Mixed', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect', $noTour]]);

        $html = $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->getContent();
        $this->assertStringContainsString('1 of 2 features in the waves have a tour', $html);
    }

    public function test_a_tour_on_a_corex_page_is_matched_through_the_feature_route_prefix(): void
    {
        // Training's pages live at corex/training and corex/training-help while its route_prefixes are written
        // without "corex/" — the Training tours were reported as "no tour yet" until both forms were matched.
        $by = app(FeatureTourCoverage::class)->toursByFeature();
        $keys = array_column($by['training'] ?? [], 'key');
        $this->assertContains('train-courses', $keys);
        $this->assertContains('train-help', $keys);
    }

    public function test_every_feature_a_wave_can_hold_has_a_tour(): void
    {
        // A tour may name its feature outright when its page belongs to another feature (Marketing on the
        // property page); that label wins over the permission/route match. An unknown key is ignored.
        $coverage = app(FeatureTourCoverage::class);
        $this->assertSame('marketing', $coverage->featureFor(\App\Support\Tours\TourRegistry::all()['smk-mk-hub']));
        $this->assertNull($coverage->featureFor(['feature' => 'no-such-feature']));

        $covered = array_keys($coverage->toursByFeature());
        $missing = array_values(array_diff(array_keys(app(\App\Services\FeatureRollout\FeatureRolloutService::class)->waveableFeatures()), $covered));
        $this->assertSame([], $missing, 'Features with no guided tour: ' . implode(', ', $missing));
    }
}
