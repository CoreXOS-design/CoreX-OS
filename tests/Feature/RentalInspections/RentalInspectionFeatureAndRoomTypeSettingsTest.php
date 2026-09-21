<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Johan, 2026-09-20 — two settings pieces feeding cc4's inspections rework:
 *
 * (1) "we have a features list in settings somewhere. should we expand it
 * that we tick which features are allowed on inspections? I think its the
 * better shape than relying on the system to do it automatically and get
 * it right." The existing property feature catalog
 * (config('property-spaces.feature_categories')) stays completely
 * unchanged — every label there is keyed on by Property24/Private Property
 * syndication's FEATURE_TAG_MAP, so this is a purely additive, agency-scoped
 * overlay (RentalInspectionSetting::inspection_feature_labels), never a
 * redesign of the catalog itself.
 *
 * (2) "we should have a setting somewhere on rentals that defines room
 * types and what gets added - ceiling, walls, floors, windows, doors -
 * that should be a std. if its a patio as example there are still things
 * to check." Interface agreed with cc4 before building: an ordered array
 * of default item labels per space type
 * (config('property-spaces.all_space_types')'s own strings), agency
 * overrides merged on top, keyed the same way
 * rental_inspection_items.space_type already is.
 */
final class RentalInspectionFeatureAndRoomTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Agency $agency): User
    {
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function newAgency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
    }

    // ── Job One: inspection feature labels ──────────────────────────────

    public function test_default_inspection_feature_labels_are_all_genuinely_in_the_live_catalog(): void
    {
        $catalog = collect(config('property-spaces.feature_categories', []))
            ->flatMap(fn ($category) => $category['features'] ?? [])
            ->all();

        foreach (RentalInspectionSetting::DEFAULT_INSPECTION_FEATURE_LABELS as $label) {
            $this->assertContains($label, $catalog, "default label '{$label}' must exist in the live feature catalog");
        }
    }

    public function test_an_agency_with_no_row_gets_the_default_feature_labels(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertSame(
            RentalInspectionSetting::DEFAULT_INSPECTION_FEATURE_LABELS,
            RentalInspectionSetting::inspectionFeatureLabelsFor($agency->id)
        );
    }

    public function test_an_agency_can_save_a_genuinely_different_feature_selection(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->get(route('corex.settings.rental-inspections.edit'));

        $response = $this->actingAs($admin)
            ->post(route('corex.settings.rental-inspections.features'), [
                'inspection_features_submitted' => '1',
                // Both are real, valid catalog labels; neither is in the
                // shipped default set (Freehold is explicitly excluded by
                // Johan's own example) — a genuinely different selection,
                // not just the defaults re-saved.
                'inspection_feature_labels' => ['Freehold', 'Fibre'],
            ]);

        $response->assertRedirect(route('corex.settings.rental-inspections.edit'));
        $response->assertSessionHasNoErrors();

        $this->assertSame(['Freehold', 'Fibre'], RentalInspectionSetting::inspectionFeatureLabelsFor($agency->id));
    }

    /**
     * "Pool Shed" is not a real label in config('property-spaces.feature_categories')
     * — it's deliberately invented to prove a submitted label that isn't in
     * the live catalog is silently dropped, not trusted as-is. This is the
     * same defensive filtering used everywhere else in this codebase for a
     * client-submitted list against a known catalog.
     */
    public function test_a_label_not_in_the_live_catalog_is_silently_filtered_out(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.features'), [
            'inspection_features_submitted' => '1',
            'inspection_feature_labels' => ['Not A Real Feature', 'Balcony'],
        ]);

        $this->assertSame(['Balcony'], RentalInspectionSetting::inspectionFeatureLabelsFor($agency->id));
    }

    /** An agency explicitly unticking every feature is a real, preserved state — not coerced back to defaults. */
    public function test_an_agency_can_explicitly_select_zero_features(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.features'), [
            'inspection_features_submitted' => '1',
            'inspection_feature_labels' => [],
        ]);

        $this->assertSame([], RentalInspectionSetting::inspectionFeatureLabelsFor($agency->id));
    }

    /** The has()-guard: an absent submitted marker (e.g. a stale/partial form) must not silently wipe a saved selection. */
    public function test_the_feature_saver_never_wipes_a_selection_the_request_never_rendered(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        RentalInspectionSetting::create(['agency_id' => $agency->id, 'inspection_feature_labels' => ['Solar Panel']]);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.features'), [
            // No inspection_features_submitted marker at all.
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(['Solar Panel'], RentalInspectionSetting::inspectionFeatureLabelsFor($agency->id), 'a request missing the submitted marker must not touch the saved selection');
    }

    public function test_a_second_agencys_feature_selection_survives_another_agencys_save(): void
    {
        $agencyA = $this->newAgency('Agency A');
        RentalInspectionSetting::create(['agency_id' => $agencyA->id, 'inspection_feature_labels' => ['Borehole']]);

        $agencyB = $this->newAgency('Agency B');
        $adminB = $this->admin($agencyB);

        $this->actingAs($adminB)->post(route('corex.settings.rental-inspections.features'), [
            'inspection_features_submitted' => '1',
            'inspection_feature_labels' => ['Wi-Fi'],
        ]);

        $this->assertSame(['Wi-Fi'], RentalInspectionSetting::inspectionFeatureLabelsFor($agencyB->id));
        $this->assertSame(['Borehole'], RentalInspectionSetting::inspectionFeatureLabelsFor($agencyA->id), 'Agency A must be untouched by Agency B\'s save');
    }

    // ── Job Two: room type default items ────────────────────────────────

    public function test_every_known_space_type_defaults_to_the_standard_baseline(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $defaults = RentalInspectionSetting::roomTypeItemDefaultsFor($agency->id);

        foreach (config('property-spaces.all_space_types', []) as $type) {
            $this->assertArrayHasKey($type, $defaults);
            $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, $defaults[$type], "space type '{$type}' must default to the standard baseline");
        }
    }

    public function test_roomTypeItemsFor_falls_back_to_the_standard_baseline_for_an_uncustomized_or_unknown_type(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Bedroom'));
        // A type that doesn't even exist in the catalog — must still seed something, never nothing.
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Some Future Room Type'));
    }

    public function test_an_agency_can_customize_a_room_type_down_to_fewer_items(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => [
                'Patio' => ['Floors', 'Railing'],
            ],
        ]);

        $response->assertRedirect(route('corex.settings.rental-inspections.edit'));
        $response->assertSessionHasNoErrors();

        $this->assertSame(['Floors', 'Railing'], RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Patio'));
        // Every OTHER type is untouched by customizing one — still the standard baseline.
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Bedroom'));
    }

    /** An agency explicitly setting a type to zero items is a real, preserved state, distinguishable from "never customized." */
    public function test_an_agency_can_set_a_room_type_to_zero_items(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => [
                'Yard' => [''], // the UI's own hidden-empty-value fallback for "zero items"
            ],
        ]);

        $this->assertSame([], RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Yard'));
        $this->assertArrayHasKey('Yard', RentalInspectionSetting::customRoomTypeOverridesFor($agency->id), 'an explicit zero-item override must be saved, not treated as absent');
    }

    /** A space type not in the live catalog must never be persisted, even if submitted. */
    public function test_a_space_type_not_in_the_live_catalog_is_rejected(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => [
                'Not A Real Space Type' => ['Ceiling'],
            ],
        ]);

        $this->assertArrayNotHasKey('Not A Real Space Type', RentalInspectionSetting::customRoomTypeOverridesFor($agency->id));
    }

    /** Blank/whitespace-only items within a genuinely-submitted type are dropped, not saved as empty strings. */
    public function test_blank_items_are_trimmed_and_dropped(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => [
                'Garage' => ['Ceiling', '   ', 'Doors'],
            ],
        ]);

        $this->assertSame(['Ceiling', 'Doors'], RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Garage'));
    }

    public function test_the_room_type_saver_never_wipes_overrides_the_request_never_rendered(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        RentalInspectionSetting::create(['agency_id' => $agency->id, 'room_type_item_defaults' => ['Patio' => ['Floors']]]);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            // No room_type_item_defaults_submitted marker.
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(['Floors'], RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Patio'));
    }

    public function test_a_second_agencys_room_type_overrides_survive_another_agencys_save(): void
    {
        $agencyA = $this->newAgency('Agency A');
        RentalInspectionSetting::create(['agency_id' => $agencyA->id, 'room_type_item_defaults' => ['Garage' => ['Floors', 'Doors']]]);

        $agencyB = $this->newAgency('Agency B');
        $adminB = $this->admin($agencyB);

        $this->actingAs($adminB)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => ['Bedroom' => ['Ceiling']],
        ]);

        $this->assertSame(['Ceiling'], RentalInspectionSetting::roomTypeItemsFor($agencyB->id, 'Bedroom'));
        $this->assertSame(['Floors', 'Doors'], RentalInspectionSetting::roomTypeItemsFor($agencyA->id, 'Garage'), 'Agency A must be untouched by Agency B\'s save');
    }

    // ── Shared: the edit screen renders both sections with real state ──

    public function test_the_settings_screen_renders_current_selections_not_blanks(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        RentalInspectionSetting::create([
            'agency_id' => $agency->id,
            'inspection_feature_labels' => ['Fibre'],
            'room_type_item_defaults' => ['Patio' => ['Floors', 'Railing']],
        ]);

        $response = $this->actingAs($admin)->get(route('corex.settings.rental-inspections.edit'));
        $response->assertOk();
        $response->assertSee('Fibre');
        $response->assertSee('Patio');
        $response->assertSee('Railing');
    }

    public function test_a_fresh_agency_sees_no_hfc_wording_and_a_real_empty_state_for_room_types(): void
    {
        $agency = $this->newAgency('Cape Town Rentals');
        $admin = $this->admin($agency);

        $response = $this->actingAs($admin)->get(route('corex.settings.rental-inspections.edit'));
        $response->assertOk();

        foreach (['HFC', 'Home Finders', 'Shelly Beach', 'hfcoastal'] as $term) {
            $response->assertDontSee($term);
        }

        $response->assertSee('No room types customized yet');
    }
}
