<?php

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RoadToSold\RtsSettingAuditEntry;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-454 — Road to Sold settings (.ai/specs/road-to-sold.md sections 2.1, 3.1, 5.1, 6).
 *
 * Input paths proven: defaults with no row; agency id 0; a one-box "lazy" save; a partial post
 * (the wizard-saver shape) leaving every other field untouched; each validated field malformed;
 * green/red ordering; selection absorb rules (unknown key, missing key, reorder); no-change save
 * writes no audit; reset; second-agency isolation; direct-URL denial for a user without the key.
 */
final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithAdmin(string $name = 'Coastal Realty'): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        return [$agency, $admin];
    }

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
    }

    public function test_defaults_when_the_agency_has_no_row_and_reading_writes_nothing(): void
    {
        [$agency] = $this->agencyWithAdmin();
        $s = SettingsService::forAgency($agency->id);

        $this->assertFalse($s->isCustomised());
        $this->assertFalse($s->lockAgentsToAreas());
        $this->assertFalse($s->halfDayLeaveHalvesTarget());
        $this->assertSame(7, $s->areaRefreshWarnDays());
        $this->assertSame('show_missed', $s->missedItemsMode());
        $this->assertFalse($s->rollsOver());
        $this->assertSame(14, $s->newOnMarketDays());
        $this->assertSame(90, $s->longOnMarketDays());
        $this->assertSame(3, $s->ownerKnownQuantity());
        $this->assertSame(60, $s->stuckStockMinDaysOnMarket());
        $this->assertSame(30, $s->stuckStockMaxViews());
        $this->assertSame(2, $s->stuckStockMaxLeads());
        $this->assertSame(180, $s->hotPocketLookbackDays());
        $this->assertSame(30, $s->priceDropLookbackDays());
        $this->assertSame(30, $s->stuckStockLookbackDays());
        $this->assertSame(3, $s->noAnswerDropAfter());
        $this->assertSame(30, $s->holdDays());
        $this->assertSame(90, $s->summaryGreenFromPct());
        $this->assertSame(70, $s->summaryRedBelowPct());
        $this->assertSame([1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 0, 7 => 0], $s->defaultWeekdayTargets());
        $this->assertSame(
            ['my_buyers_sourcing', 'agency_buyers_sourcing', 'hot_pockets', 'price_drops', 'new_on_market',
             'long_on_market', 'owner_known', 'my_expired_stock', 'my_stock_not_selling', 'expired_competitor'],
            $s->enabledSelectionKeys()
        );
        $this->assertCount(6, array_filter($s->summarySteps(), fn ($i) => $i['enabled']));
        $this->assertSame(0, RtsSetting::query()->count(), 'reading must never write');
    }

    public function test_agency_id_zero_returns_defaults_and_persists_nothing(): void
    {
        $s = SettingsService::forAgency(0);
        $this->assertSame(30, $s->holdDays());
        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->count());
    }

    public function test_status_bands_follow_the_two_lines(): void
    {
        [$agency] = $this->agencyWithAdmin();
        $s = SettingsService::forAgency($agency->id);

        $this->assertSame('green', $s->statusForPct(100));
        $this->assertSame('green', $s->statusForPct(90));
        $this->assertSame('amber', $s->statusForPct(89.9));
        $this->assertSame('amber', $s->statusForPct(70));
        $this->assertSame('red', $s->statusForPct(69.9));
        $this->assertSame('red', $s->statusForPct(0));
    }

    public function test_the_page_opens_for_an_admin(): void
    {
        [, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)->get(route('road-to-sold.settings.index'))
            ->assertOk()
            ->assertSee('Road to Sold settings')
            ->assertSee('Lock agents to their areas');
    }

    public function test_the_settings_page_has_no_intro_or_explainer_paragraphs(): void
    {
        [, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)->get(route('road-to-sold.settings.index'))
            ->assertOk()
            ->assertDontSee('Follow-ups come first, then these sources')
            ->assertDontSee('These are the settings');
    }

    public function test_lazy_shortcut_ticking_one_box_saves_and_leaves_everything_else_default(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)
            ->put(route('road-to-sold.settings.general.update'), ['lock_agents_to_areas' => '1'])
            ->assertRedirect(route('road-to-sold.settings.index'))
            ->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $this->assertTrue($s->isCustomised());
        $this->assertTrue($s->lockAgentsToAreas());
        $this->assertSame(7, $s->areaRefreshWarnDays());
        $this->assertSame(30, $s->holdDays());
        $this->assertSame(1, RtsSettingAuditEntry::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_a_partial_post_changes_only_what_it_carries_a_missing_checkbox_is_not_false(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), [
            'lock_agents_to_areas' => '1', 'half_day_leave_halves_target' => '1', 'hold_days' => '45',
        ])->assertSessionHasNoErrors();

        // The wizard-saver shape: a post that carries ONLY hold_days.
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '20'])
            ->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $this->assertSame(20, $s->holdDays());
        $this->assertTrue($s->lockAgentsToAreas(), 'a field that was not posted must not be reset');
        $this->assertTrue($s->halfDayLeaveHalvesTarget());
    }

    public function test_unticking_a_box_via_the_hidden_zero_turns_it_off(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['lock_agents_to_areas' => '1']);
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['lock_agents_to_areas' => '0']);

        SettingsService::forget();
        $this->assertFalse(SettingsService::forAgency($agency->id)->lockAgentsToAreas());
    }

    public function test_whitespace_around_numbers_is_trimmed(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['area_refresh_warn_days' => ' 10 '])
            ->assertSessionHasNoErrors();

        SettingsService::forget();
        $this->assertSame(10, SettingsService::forAgency($agency->id)->areaRefreshWarnDays());
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public static function malformedProvider(): array
    {
        return [
            'refresh days zero'     => ['area_refresh_warn_days', '0'],
            'refresh days text'     => ['area_refresh_warn_days', 'soon'],
            'new days too big'      => ['new_on_market_days', '999'],
            'long days text'        => ['long_on_market_days', 'x'],
            'owner qty negative'    => ['owner_known_quantity', '-1'],
            'stuck days zero'       => ['stuck_stock_min_days', '0'],
            'stuck views negative'  => ['stuck_stock_max_views', '-5'],
            'stuck leads decimal'   => ['stuck_stock_max_leads', '1.5'],
            'hot pocket window 0'   => ['hot_pocket_lookback_days', '0'],
            'price drop window text' => ['price_drop_lookback_days', 'lately'],
            'stuck window too big'  => ['stuck_stock_lookback_days', '99999'],
            'no-answer zero'        => ['no_answer_drop_after', '0'],
            'no-answer too many'    => ['no_answer_drop_after', '21'],
            'hold days zero'        => ['hold_days', '0'],
            'green over 100'        => ['summary_green_from_pct', '101'],
            'red zero'              => ['summary_red_below_pct', '0'],
            'missed mode unknown'   => ['missed_items_mode', 'ignore'],
            'lock not a boolean'    => ['lock_agents_to_areas', 'maybe'],
        ];
    }

    /** @dataProvider malformedProvider */
    public function test_each_malformed_value_is_refused_with_a_message_and_nothing_is_saved(string $field, mixed $value): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)
            ->from(route('road-to-sold.settings.index'))
            ->put(route('road-to-sold.settings.general.update'), [$field => $value])
            ->assertSessionHasErrors($field);

        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_green_must_sit_above_red_even_on_a_partial_post(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), [
            'summary_green_from_pct' => '60', 'summary_red_below_pct' => '60',
        ])->assertSessionHasErrors('summary_red_below_pct');

        // Only red posted, above the (default) green line of 90 -> refused.
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['summary_red_below_pct' => '95'])
            ->assertSessionHasErrors('summary_red_below_pct');

        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_selections_order_and_switching_off_persist_and_odd_payloads_are_absorbed(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), [
            'selections' => [
                ['key' => 'price_drops', 'enabled' => '1'],
                ['key' => 'my_buyers_sourcing', 'enabled' => '0'],
                ['key' => 'price_drops', 'enabled' => '1'],        // duplicate -> collapsed
                ['key' => 'not_a_real_selection', 'enabled' => '1'], // unknown -> dropped
                ['key' => 'hot_pockets', 'enabled' => '1'],
            ],
        ])->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $all = $s->selections();

        $this->assertCount(10, $all, 'every known selection is always present');
        $this->assertSame(['price_drops', 'my_buyers_sourcing', 'hot_pockets'], array_slice(array_column($all, 'key'), 0, 3));
        $this->assertSame(['price_drops', 'hot_pockets'], $s->enabledSelectionKeys(), 'missing keys are appended switched OFF');
        $this->assertFalse($s->selectionEnabled('my_buyers_sourcing'));
        $this->assertSame('Price drops', $all[0]['label']);
    }

    public function test_switching_every_selection_off_is_accepted(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $off = array_map(fn ($k) => ['key' => $k, 'enabled' => '0'], array_keys(RtsSetting::selectionCatalogue()));

        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['selections' => $off])
            ->assertSessionHasNoErrors();

        SettingsService::forget();
        $this->assertSame([], SettingsService::forAgency($agency->id)->enabledSelectionKeys());
    }

    public function test_summary_steps_can_be_switched_off(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $steps = [];
        foreach (array_keys(RtsSetting::summaryStepCatalogue()) as $k) {
            $steps[] = ['key' => $k, 'enabled' => $k === 'calls_made' ? '1' : '0'];
        }
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['summary_steps' => $steps])
            ->assertSessionHasNoErrors();

        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $this->assertTrue($s->summaryStepEnabled('calls_made'));
        $this->assertFalse($s->summaryStepEnabled('conversations'));
        $this->assertFalse($s->summaryStepEnabled('social_shares'), 'social shares is not a step and never enabled');
    }

    public function test_saving_with_no_change_writes_no_audit_row(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '30'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, RtsSettingAuditEntry::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_audit_records_before_and_after(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '45']);
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '60']);

        $last = RtsSettingAuditEntry::withoutGlobalScopes()->where('agency_id', $agency->id)->orderByDesc('id')->first();
        $this->assertSame(['hold_days' => 45], $last->old_values);
        $this->assertSame(['hold_days' => 60], $last->new_values);
        $this->assertSame($admin->id, (int) $last->changed_by_user_id);
    }

    public function test_reset_puts_everything_back_to_the_defaults(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '99', 'lock_agents_to_areas' => '1']);

        $this->actingAs($admin)->post(route('road-to-sold.settings.general.reset'))->assertRedirect();

        SettingsService::forget();
        $s = SettingsService::forAgency($agency->id);
        $this->assertSame(30, $s->holdDays());
        $this->assertFalse($s->lockAgentsToAreas());
    }

    public function test_reset_with_no_saved_row_is_a_harmless_no_op(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin)->post(route('road-to-sold.settings.general.reset'))->assertRedirect();
        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_a_second_agency_has_its_own_settings_and_sees_none_of_the_first(): void
    {
        [$a1, $admin1] = $this->agencyWithAdmin('Coastal Realty');
        [$a2, $admin2] = $this->agencyWithAdmin('Cape Town Rentals');

        $this->actingAs($admin1)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '77', 'lock_agents_to_areas' => '1']);
        SettingsService::forget();

        $this->assertSame(77, SettingsService::forAgency($a1->id)->holdDays());
        $this->assertSame(30, SettingsService::forAgency($a2->id)->holdDays());
        $this->assertFalse(SettingsService::forAgency($a2->id)->lockAgentsToAreas());

        $this->actingAs($admin2)->get(route('road-to-sold.settings.index'))->assertOk()->assertDontSee('value="77"', false);
    }

    public function test_an_agent_without_the_manage_key_cannot_open_or_save_by_direct_url(): void
    {
        [$agency] = $this->agencyWithAdmin();
        $branch = Branch::where('agency_id', $agency->id)->first();
        $agent  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        // A populated grants table (an empty one permits everything in tests): the agent holds only the access key.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $agency->id]);
        PermissionService::clearCache();

        $this->actingAs($agent)->get(route('road-to-sold.settings.index'))->assertForbidden();
        $this->actingAs($agent)->put(route('road-to-sold.settings.general.update'), ['hold_days' => '5'])->assertForbidden();
        $this->actingAs($agent)->post(route('road-to-sold.settings.general.reset'))->assertForbidden();

        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_the_feature_row_and_permission_keys_exist(): void
    {
        $this->assertArrayHasKey('road-to-sold', config('corex-features'));
        $keys = array_column(config('corex-permissions.permissions'), 'key');
        foreach (['access_road_to_sold', 'road_to_sold.view', 'road_to_sold.manage_settings', 'road_to_sold.manage_areas', 'road_to_sold.manage_targets'] as $k) {
            $this->assertContains($k, $keys);
        }
    }
}
