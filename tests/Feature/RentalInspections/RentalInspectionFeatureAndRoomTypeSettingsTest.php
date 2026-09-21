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

    public function test_every_known_space_type_defaults_to_the_standard_baseline_except_the_five_with_a_real_vocabulary(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $defaults = RentalInspectionSetting::roomTypeItemDefaultsFor($agency->id);
        $specialCased = array_keys(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE);

        foreach (config('property-spaces.all_space_types', []) as $type) {
            $this->assertArrayHasKey($type, $defaults);
            if (in_array($type, $specialCased, true)) {
                continue;
            }
            $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, $defaults[$type], "space type '{$type}' must default to the standard baseline");
        }
    }

    /** 2026-09-21 — Retha's real vocabulary, transcribed exactly, is the system default for these five. */
    public function test_the_five_named_types_default_to_rethas_real_vocabulary(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        foreach (RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE as $type => $items) {
            $this->assertSame($items, RentalInspectionSetting::roomTypeItemsFor($agency->id, $type), "space type '{$type}' must default to Retha's transcribed list");
        }
    }

    public function test_roomTypeItemsFor_falls_back_to_the_standard_baseline_for_an_uncustomized_or_unknown_type(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Lounge'));
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
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Lounge'));
        // ...including a type with its OWN real-vocabulary system default, also untouched.
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS_BY_TYPE['Bedroom'], RentalInspectionSetting::roomTypeItemsFor($agency->id, 'Bedroom'));
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

    // ── Job Three: room walking order — 2026-09-21, Johan on property 5792 ──

    public function test_default_walking_order_covers_every_known_space_type_exactly_once(): void
    {
        $catalog = config('property-spaces.all_space_types', []);

        $this->assertSame(count($catalog), count(RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER));
        $this->assertSame(count($catalog), count(array_unique(RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER)));
        $this->assertEmpty(array_diff($catalog, RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER), 'every catalog type must appear in the default walking order');
        $this->assertEmpty(array_diff(RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER, $catalog), 'the default walking order must never name a type outside the live catalog');
    }

    public function test_an_agency_with_no_row_gets_the_default_walking_order(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertSame(
            RentalInspectionSetting::DEFAULT_ROOM_TYPE_WALKING_ORDER,
            RentalInspectionSetting::roomTypeWalkingOrderFor($agency->id)
        );
    }

    public function test_an_agency_can_save_a_genuinely_different_walking_order(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        $customOrder = array_reverse(config('property-spaces.all_space_types', []));

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-order'), [
            'room_type_walking_order_submitted' => '1',
            'room_type_walking_order' => $customOrder,
        ]);

        $response->assertRedirect(route('corex.settings.rental-inspections.edit'));
        $response->assertSessionHasNoErrors();
        $this->assertSame($customOrder, RentalInspectionSetting::roomTypeWalkingOrderFor($agency->id));
    }

    public function test_a_type_not_in_the_live_catalog_is_dropped_from_a_saved_order(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-order'), [
            'room_type_walking_order_submitted' => '1',
            'room_type_walking_order' => ['Bedroom', 'Not A Real Space Type', 'Kitchen'],
        ]);

        $saved = RentalInspectionSetting::roomTypeWalkingOrderFor($agency->id);
        $this->assertNotContains('Not A Real Space Type', $saved);
        $this->assertSame(0, array_search('Bedroom', $saved, true));
        $this->assertSame(1, array_search('Kitchen', $saved, true));
    }

    /** A saved order predating a later catalog addition must still place every type — nothing left unsortable. */
    public function test_a_saved_order_missing_a_catalog_type_gets_it_appended_automatically(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        RentalInspectionSetting::create(['agency_id' => $agency->id, 'room_type_walking_order' => ['Bedroom', 'Kitchen']]);

        $resolved = RentalInspectionSetting::roomTypeWalkingOrderFor($agency->id);

        $this->assertSame(['Bedroom', 'Kitchen'], array_slice($resolved, 0, 2));
        $this->assertSame(count(config('property-spaces.all_space_types', [])), count($resolved));
        $this->assertContains('Garage', $resolved, 'every catalog type must resolve to SOME position, even one the agency never explicitly ordered');
    }

    public function test_the_walking_order_saver_never_wipes_the_order_the_request_never_rendered(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        $customOrder = ['Kitchen', 'Bedroom'];
        RentalInspectionSetting::create(['agency_id' => $agency->id, 'room_type_walking_order' => $customOrder]);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.room-type-order'), [
            // No room_type_walking_order_submitted marker.
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($customOrder, array_slice(RentalInspectionSetting::roomTypeWalkingOrderFor($agency->id), 0, 2));
    }

    public function test_a_second_agencys_walking_order_survives_another_agencys_save(): void
    {
        $agencyA = $this->newAgency('Agency A');
        RentalInspectionSetting::create(['agency_id' => $agencyA->id, 'room_type_walking_order' => ['Kitchen', 'Bedroom']]);

        $agencyB = $this->newAgency('Agency B');
        $adminB = $this->admin($agencyB);

        $this->actingAs($adminB)->post(route('corex.settings.rental-inspections.room-type-order'), [
            'room_type_walking_order_submitted' => '1',
            'room_type_walking_order' => ['Bathroom', 'Garage'],
        ]);

        $this->assertSame(['Bathroom', 'Garage'], array_slice(RentalInspectionSetting::roomTypeWalkingOrderFor($agencyB->id), 0, 2));
        $this->assertSame(['Kitchen', 'Bedroom'], array_slice(RentalInspectionSetting::roomTypeWalkingOrderFor($agencyA->id), 0, 2), 'Agency A must be untouched by Agency B\'s save');
    }

    /**
     * Johan: "natural-numeric, NOT alphabetical: alphabetical gives 1, 10, 2."
     * Bedroom sits before Kitchen in the default order, and within Bedroom,
     * the numeric suffix must rank 1 < 2 < 10 — never string comparison.
     */
    public function test_default_room_sort_order_combines_walking_position_and_natural_numeric_label(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $bedroom1 = RentalInspectionSetting::defaultRoomSortOrderFor($agency->id, 'Bedroom', 'Bedroom 1');
        $bedroom2 = RentalInspectionSetting::defaultRoomSortOrderFor($agency->id, 'Bedroom', 'Bedroom 2');
        $bedroom10 = RentalInspectionSetting::defaultRoomSortOrderFor($agency->id, 'Bedroom', 'Bedroom 10');
        $kitchen = RentalInspectionSetting::defaultRoomSortOrderFor($agency->id, 'Kitchen', 'Kitchen');

        $this->assertLessThan($bedroom2, $bedroom1);
        $this->assertLessThan($bedroom10, $bedroom2);
        // Johan's own stated order: "entrance/reception first, living spaces,
        // kitchen, bedrooms, bathrooms, then outside spaces" — kitchen before bedrooms.
        $this->assertLessThan($bedroom1, $kitchen, 'Kitchen sits before Bedroom in the default walking order');
    }

    // ── Job Four: condition states — 2026-09-21, from Retha's real paper form ──

    public function test_default_condition_states_include_the_existing_six_and_na(): void
    {
        $keys = array_column(RentalInspectionSetting::DEFAULT_CONDITION_STATES, 'key');

        $this->assertSame(['good', 'fair', 'damaged', 'not_working', 'missing', 'other', 'n_a'], $keys);
    }

    public function test_an_agency_with_no_row_gets_the_default_condition_states(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertSame(
            RentalInspectionSetting::DEFAULT_CONDITION_STATES,
            RentalInspectionSetting::conditionStatesFor($agency->id)
        );
    }

    /** Retha's real vocabulary — completely different keys and labels from the shipped default. */
    public function test_an_agency_can_save_a_fully_custom_condition_vocabulary(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.condition-states'), [
            'condition_states_submitted' => '1',
            'condition_states' => [
                ['key' => 'good', 'label' => 'Good', 'requires_notes' => '0'],
                ['key' => 'ok', 'label' => 'OK', 'requires_notes' => '0'],
                ['key' => 'bad', 'label' => 'Bad', 'requires_notes' => '1'],
            ],
        ]);

        $response->assertRedirect(route('corex.settings.rental-inspections.edit'));
        $response->assertSessionHasNoErrors();

        $saved = RentalInspectionSetting::conditionStatesFor($agency->id);
        $this->assertSame(['good', 'ok', 'bad'], array_column($saved, 'key'));
        $this->assertFalse(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, 'ok'));
        $this->assertTrue(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, 'bad'));
    }

    public function test_a_row_with_a_blank_label_is_dropped(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.condition-states'), [
            'condition_states_submitted' => '1',
            'condition_states' => [
                ['key' => 'good', 'label' => 'Good', 'requires_notes' => '0'],
                ['key' => 'blank_one', 'label' => '', 'requires_notes' => '0'],
            ],
        ]);

        $this->assertSame(['good'], array_column(RentalInspectionSetting::conditionStatesFor($agency->id), 'key'));
    }

    public function test_duplicate_keys_keep_only_the_first(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-inspections.condition-states'), [
            'condition_states_submitted' => '1',
            'condition_states' => [
                ['key' => 'good', 'label' => 'Good', 'requires_notes' => '0'],
                ['key' => 'good', 'label' => 'Duplicate Good', 'requires_notes' => '1'],
            ],
        ]);

        $saved = RentalInspectionSetting::conditionStatesFor($agency->id);
        $this->assertCount(1, $saved);
        $this->assertSame('Good', $saved[0]['label']);
    }

    /** An agency with zero condition states could never record a single observation. */
    public function test_saving_zero_condition_states_is_rejected(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        RentalInspectionSetting::create(['agency_id' => $agency->id, 'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => false]]]);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.condition-states'), [
            'condition_states_submitted' => '1',
            'condition_states' => [],
        ]);

        $response->assertSessionHasErrors();
        $this->assertNotEmpty(RentalInspectionSetting::conditionStatesFor($agency->id));
    }

    public function test_the_condition_states_saver_never_wipes_the_vocabulary_the_request_never_rendered(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        RentalInspectionSetting::create(['agency_id' => $agency->id, 'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => false]]]);

        $response = $this->actingAs($admin)->post(route('corex.settings.rental-inspections.condition-states'), [
            // No condition_states_submitted marker.
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(['good'], array_column(RentalInspectionSetting::conditionStatesFor($agency->id), 'key'));
    }

    public function test_a_second_agencys_condition_states_survive_another_agencys_save(): void
    {
        $agencyA = $this->newAgency('Agency A');
        RentalInspectionSetting::create(['agency_id' => $agencyA->id, 'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => false], ['key' => 'ok', 'label' => 'OK', 'requires_notes' => false]]]);

        $agencyB = $this->newAgency('Agency B');
        $adminB = $this->admin($agencyB);

        $this->actingAs($adminB)->post(route('corex.settings.rental-inspections.condition-states'), [
            'condition_states_submitted' => '1',
            'condition_states' => [['key' => 'good', 'label' => 'Good', 'requires_notes' => '0']],
        ]);

        $this->assertSame(['good'], array_column(RentalInspectionSetting::conditionStatesFor($agencyB->id), 'key'));
        $this->assertSame(['good', 'ok'], array_column(RentalInspectionSetting::conditionStatesFor($agencyA->id), 'key'), 'Agency A must be untouched by Agency B\'s save');
    }

    public function test_condition_requires_notes_for_an_unknown_key_defaults_to_true(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertTrue(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, 'some_key_nobody_configured'));
    }

    /** Johan: N/A is "not an argument at all" — the one exception alongside Good. */
    public function test_good_and_na_do_not_require_notes_by_default_every_other_state_does(): void
    {
        $agency = $this->newAgency('Coastal Realty');

        $this->assertFalse(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, 'good'));
        $this->assertFalse(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, 'n_a'));
        foreach (['fair', 'damaged', 'not_working', 'missing', 'other'] as $key) {
            $this->assertTrue(RentalInspectionSetting::conditionRequiresNotesFor($agency->id, $key), "'{$key}' should require a reason by default");
        }
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
