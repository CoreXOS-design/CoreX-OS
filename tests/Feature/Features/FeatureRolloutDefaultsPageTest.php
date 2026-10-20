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

    public function test_search_and_track_filter(): void
    {
        $owner = $this->owner();
        $paper = FeatureRolloutWave::create(['title' => 'Zebra Paperwork', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect']]);
        $letting = FeatureRolloutWave::create(['title' => 'Quokka Letting', 'track' => 'rentals', 'position' => 20, 'feature_keys' => ['rentals']]);
        // Each listed wave renders its own edit form; the tour gap report (unfiltered) may still name a wave.
        $row = fn (FeatureRolloutWave $w) => route('admin.feature-rollout-defaults.update', $w->id);

        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['track' => 'rentals']))
            ->assertSee($row($letting), false)->assertDontSee($row($paper), false);
        // Search by a FEATURE's name finds the wave that contains it.
        $label = config('corex-features.docuperfect.label');
        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['q' => $label]))
            ->assertSee($row($paper), false)->assertDontSee($row($letting), false);
    }

    public function test_tour_gap_report_lists_exactly_the_waved_features_without_a_tour(): void
    {
        $owner = $this->owner();
        $covered = array_keys(app(FeatureTourCoverage::class)->toursByFeature());
        $this->assertContains('docuperfect', $covered, 'DocuPerfect has tours (defs/docuperfect.php)');

        $noTour = collect(array_keys(app(\App\Services\FeatureRollout\FeatureRolloutService::class)->waveableFeatures()))
            ->first(fn ($k) => ! in_array($k, $covered, true));
        $this->assertNotNull($noTour);

        FeatureRolloutWave::create(['title' => 'Mixed', 'track' => 'general', 'position' => 10, 'feature_keys' => ['docuperfect', $noTour]]);

        $html = $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->getContent();
        $this->assertStringContainsString('1 of 2 features in the waves have a tour', $html);
    }
}
