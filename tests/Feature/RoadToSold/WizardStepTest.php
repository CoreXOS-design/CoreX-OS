<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * AT-454 - the Road to Sold step of the set-up wizard (non-negotiable 10a; onboarding spec 6.1).
 *
 * Input paths proven: the step renders with the standard defaults and the explainer; saving a browser-shaped
 * post writes through the settings saver; a SUBSET post (only hold_days) leaves everything else alone;
 * the settings-page-only fields (selection order, thresholds, colour lines, summary steps) survive a wizard
 * save untouched; a negative day target and a blanked day target are refused with nothing saved and the step
 * not marked complete; a user without the settings key is told so and nothing is written; the step disappears
 * when the feature is switched off; the page shows what the agency really saved (the current-values bug class).
 */
final class WizardStepTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $branch       = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        $s = new AgencyOnboardingSetup();
        $s->agency_id       = $this->agency->id;
        $s->token           = AgencyOnboardingSetup::generateToken();
        $s->slug            = AgencyOnboardingSetup::generateSlug($this->agency->name, $this->agency->id);
        $s->current_step    = 1;
        $s->completed_steps = [];
        $s->expires_at      = now()->addDays(30);
        $s->save();
    }

    private function postStep(array $fields)
    {
        return $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'road_to_sold']), $fields);
    }

    private function completed(): array
    {
        return (array) AgencyOnboardingSetup::where('agency_id', $this->agency->id)->value('completed_steps');
    }

    public function test_the_step_is_in_the_flow_after_market_intelligence_and_explains_itself(): void
    {
        $steps = AgencyOnboardingSetup::STEPS;
        $this->assertSame(array_search('market_intelligence', $steps) + 1, array_search('road_to_sold', $steps));

        $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'road_to_sold']))
            ->assertOk()
            ->assertSee('What Road to Sold is')
            ->assertSee('What this changes:')
            ->assertSee('Monday target')
            ->assertSee('Lock agents to their areas')
            ->assertSee('Half-day leave halves the target');
    }

    public function test_the_step_shows_the_standard_defaults_for_an_agency_that_has_saved_nothing(): void
    {
        $fields = $this->browserFormFields($this->admin, 'road_to_sold');

        $this->assertSame('5', (string) $fields['default_target_1']);
        $this->assertSame('5', (string) $fields['default_target_5']);
        $this->assertSame('0', (string) $fields['default_target_6']);
        $this->assertSame('0', (string) $fields['default_target_7']);
        $this->assertSame('0', (string) $fields['lock_agents_to_areas']);
        $this->assertSame('7', (string) $fields['area_refresh_warn_days']);
        $this->assertSame('show_missed', $fields['missed_items_mode']);
        $this->assertSame('3', (string) $fields['no_answer_drop_after']);
        $this->assertSame('30', (string) $fields['hold_days']);
    }

    public function test_the_portals_we_capture_from_are_in_the_step_and_save_through_the_same_saver(): void
    {
        $fields = $this->browserFormFields($this->admin, 'road_to_sold');
        $this->assertSame('1', (string) $fields['refresh_portal_p24']);
        $this->assertSame('1', (string) $fields['refresh_portal_pp']);

        $this->postStep(array_merge($fields, ['refresh_portal_pp' => '0']))->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(['p24'], SettingsService::forAgency($this->agency->id)->refreshPortals());

        // Both off is refused and nothing changes.
        $this->postStep(array_merge($fields, ['refresh_portal_p24' => '0', 'refresh_portal_pp' => '0']))->assertSessionHasErrors();
        SettingsService::forget();
        $this->assertSame(['p24'], SettingsService::forAgency($this->agency->id)->refreshPortals());

        // The step shows what was saved, not the control default.
        $this->assertSame('0', (string) $this->browserFormFields($this->admin, 'road_to_sold')['refresh_portal_pp']);

        // A step post that never carried the toggles leaves the setting alone.
        $this->postStep(['hold_days' => '40'])->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(['p24'], SettingsService::forAgency($this->agency->id)->refreshPortals());
    }

    public function test_accepting_the_defaults_in_one_click_works_and_marks_the_step_complete(): void
    {
        $fields = $this->browserFormFields($this->admin, 'road_to_sold');

        $this->postStep($fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertContains('road_to_sold', $this->completed());
        SettingsService::forget();
        $this->assertSame(30, SettingsService::forAgency($this->agency->id)->holdDays());
    }

    public function test_a_browser_shaped_save_writes_through_the_settings_saver(): void
    {
        $fields = array_merge($this->browserFormFields($this->admin, 'road_to_sold'), [
            'default_target_1' => '9', 'default_target_6' => '2', 'lock_agents_to_areas' => '1',
            'half_day_leave_halves_target' => '1', 'area_refresh_warn_days' => '10', 'missed_items_mode' => 'roll_over',
            'no_answer_drop_after' => '4', 'hold_days' => '45',
        ]);

        $this->postStep($fields)->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame([1 => 9, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 2, 7 => 0], $s->defaultWeekdayTargets());
        $this->assertTrue($s->lockAgentsToAreas());
        $this->assertTrue($s->halfDayLeaveHalvesTarget());
        $this->assertSame(10, $s->areaRefreshWarnDays());
        $this->assertTrue($s->rollsOver());
        $this->assertSame(4, $s->noAnswerDropAfter());
        $this->assertSame(45, $s->holdDays());
        $this->assertContains('road_to_sold', $this->completed());
    }

    public function test_a_subset_post_changes_only_what_it_carries(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), [
            'lock_agents_to_areas' => '1', 'hold_days' => '60',
        ])->assertSessionHasNoErrors();

        // The shape a stale / partial step form would post: just one field.
        $this->postStep(['no_answer_drop_after' => '5'])->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(5, $s->noAnswerDropAfter());
        $this->assertTrue($s->lockAgentsToAreas(), 'a field the step did not post must not be reset');
        $this->assertSame(60, $s->holdDays());
        $this->assertSame([1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0], $s->defaultWeekdayTargets(), 'no default_target_* posted = week untouched');
    }

    public function test_settings_that_live_only_on_the_settings_page_survive_a_wizard_save(): void
    {
        // Configured the way a live agency would be: reordered sources, edited limits, changed colour lines, a step switched off.
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), [
            'selections' => [
                ['key' => 'price_drops', 'enabled' => '1'], ['key' => 'new_on_market', 'enabled' => '1'],
                ['key' => 'my_buyers_sourcing', 'enabled' => '0'],
            ],
            'new_on_market_days' => '21', 'long_on_market_days' => '120', 'owner_known_quantity' => '6',
            'stuck_stock_min_days' => '75', 'summary_green_from_pct' => '95', 'summary_red_below_pct' => '60',
            'hot_pocket_lookback_days' => '365',
            'summary_steps' => [['key' => 'calls_made', 'enabled' => '1'], ['key' => 'conversations', 'enabled' => '0']],
        ])->assertSessionHasNoErrors();

        $fields = array_merge($this->browserFormFields($this->admin, 'road_to_sold'), ['hold_days' => '33']);
        $this->postStep($fields)->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id);
        $this->assertSame(33, $s->holdDays());
        $this->assertSame(['price_drops', 'new_on_market'], $s->enabledSelectionKeys());
        $this->assertSame('price_drops', $s->selections()[0]['key']);
        $this->assertSame(21, $s->newOnMarketDays());
        $this->assertSame(120, $s->longOnMarketDays());
        $this->assertSame(6, $s->ownerKnownQuantity());
        $this->assertSame(75, $s->stuckStockMinDaysOnMarket());
        $this->assertSame(95, $s->summaryGreenFromPct());
        $this->assertSame(60, $s->summaryRedBelowPct());
        $this->assertSame(365, $s->hotPocketLookbackDays());
        $this->assertFalse($s->summaryStepEnabled('conversations'));
    }

    public function test_the_step_shows_what_the_agency_really_saved(): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), [
            'hold_days' => '41', 'lock_agents_to_areas' => '1', 'missed_items_mode' => 'roll_over',
        ]);
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.targets.default'), [
            'default_targets' => [1 => 8, 2 => 7, 3 => 6, 4 => 5, 5 => 4, 6 => 3, 7 => 2],
        ]);
        SettingsService::forget();

        $fields = $this->browserFormFields($this->admin, 'road_to_sold');
        $this->assertSame('41', (string) $fields['hold_days']);
        $this->assertSame('1', (string) $fields['lock_agents_to_areas']);
        $this->assertSame('roll_over', $fields['missed_items_mode']);
        $this->assertSame('8', (string) $fields['default_target_1']);
        $this->assertSame('2', (string) $fields['default_target_7']);
    }

    public function test_a_negative_or_blank_day_target_is_refused_nothing_is_saved_and_the_step_stays_open(): void
    {
        $base = $this->browserFormFields($this->admin, 'road_to_sold');

        $this->postStep(array_merge($base, ['default_target_3' => '-2', 'hold_days' => '99']))->assertSessionHasErrors();
        $this->postStep(array_merge($base, ['default_target_4' => '', 'hold_days' => '99']))->assertSessionHasErrors();
        $this->postStep(array_merge($base, ['hold_days' => 'forever']))->assertSessionHasErrors('hold_days');
        $this->postStep(array_merge($base, ['missed_items_mode' => 'ignore']))->assertSessionHasErrors('missed_items_mode');

        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'a refused step must write nothing');
        $this->assertNotContains('road_to_sold', $this->completed());
    }

    public function test_a_user_without_the_settings_key_is_told_so_and_nothing_is_written(): void
    {
        // Seed SOME grants so the unseeded "allow all" test fallback does not apply: this admin may run the
        // wizard and open settings, but does NOT hold road_to_sold.manage_settings.
        RolePermission::create(['role' => 'admin', 'permission_key' => 'agency_setup.run', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $this->postStep(['hold_days' => '99'])->assertSessionHasErrors('permission');

        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $this->assertNotContains('road_to_sold', $this->completed());
    }

    public function test_the_step_is_skipped_when_the_feature_is_switched_off(): void
    {
        $this->assertTrue(AgencyOnboardingSetup::stepIsActive('road_to_sold', $this->agency));

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'road-to-sold'], ['enabled' => false]);
        app(AgencyFeatureService::class)->forget($this->agency->id);

        $this->assertFalse(AgencyOnboardingSetup::stepIsActive('road_to_sold', $this->agency));
        $this->assertNotContains('road_to_sold', AgencyOnboardingSetup::activeSteps($this->agency));
    }

    public function test_the_step_is_skipped_when_prospecting_is_off_because_road_to_sold_depends_on_it(): void
    {
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'prospecting'], ['enabled' => false]);
        app(AgencyFeatureService::class)->forget($this->agency->id);

        $this->assertFalse(AgencyOnboardingSetup::stepIsActive('road_to_sold', $this->agency));
    }

    public function test_a_second_agency_starts_from_the_standard_defaults_not_the_first_agencys_numbers(): void
    {
        RtsSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'track' => 'sales', 'hold_days' => 77]);

        $other   = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch  = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $capeAdmin = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $other->id; $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($other->name, $other->id);
        $s->current_step = 1; $s->completed_steps = []; $s->expires_at = now()->addDays(30); $s->save();
        SettingsService::forget();

        $fields = $this->browserFormFields($capeAdmin, 'road_to_sold');
        $this->assertSame('30', (string) $fields['hold_days']);
    }

    public function test_the_minimum_for_a_working_area_is_in_the_step_and_areas_are_said_to_come_from_stock_and_mic(): void
    {
        $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'road_to_sold']))->assertOk()
            ->assertSee('A suburb counts as a working area from')
            ->assertSee('are not chosen here');

        $fields = $this->browserFormFields($this->admin, 'road_to_sold');
        $this->assertSame('1', (string) $fields['min_area_properties']);
        $this->postStep(array_merge($fields, ['min_area_properties' => '3']))->assertSessionHasNoErrors();
        SettingsService::forget();
        $this->assertSame(3, SettingsService::forAgency($this->agency->id)->minAreaProperties());
        $this->assertTrue(\App\Models\RoadToSold\RtsSetting::query()->where('agency_id', $this->agency->id)->exists());
    }
}
