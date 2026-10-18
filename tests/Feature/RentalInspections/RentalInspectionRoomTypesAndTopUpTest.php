<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Http\Controllers\CoreX\RentalListsWizardSaver;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.4 (Build I-2) — the agency's own room
 * types, and the "Add missing standard items" top-up for an existing room.
 *
 * Input paths proven (BUILD_STANDARD §5): custom type — add / blank / duplicate
 * of a standard type / duplicate of its own (case + spacing) / over-long label /
 * 250 submitted / bogus posted key / rename keeps key / archive / restore /
 * remove-and-re-add in one save / second agency untouched / wizard no-op without
 * the marker / wizard never wipes an archived one; top-up — thin room / full
 * room / case+spacing match / retired match never re-added / only ticked ones /
 * stale or forged label adds nothing / repeat adds nothing / nothing ticked /
 * agency override respected / custom-type room / retired room / other property /
 * other agency; recorded observations and item order untouched.
 */
final class RentalInspectionRoomTypesAndTopUpTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RT Agency', 'slug' => 'rt-agency-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => '14 Marine Drive, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function saveTypes(array $rows, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('corex.settings.rental-inspections.custom-room-types'), [
            'custom_room_types_submitted' => '1',
            'custom_room_types' => $rows,
        ]);
    }

    private function types(?int $agencyId = null): array
    {
        return RentalInspectionSetting::customRoomTypesFor($agencyId ?? $this->agency->id);
    }

    private function room(string $type, string $label, array $items = [], ?Property $property = null): PropertyRoom
    {
        $property ??= $this->property;
        $room = PropertyRoom::create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id, 'type' => $type, 'label' => $label,
            'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id,
        ]);
        foreach ($items as $i => $itemLabel) {
            RentalInspectionItem::create([
                'agency_id' => $property->agency_id, 'property_id' => $property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $itemLabel, 'space_type' => $type,
                'source' => 'manual', 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
            ]);
        }

        return $room;
    }

    // ── Custom room types ───────────────────────────────────────────────

    public function test_an_agency_can_add_its_own_room_type_and_it_is_offered_for_new_rooms(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space', 'archived' => '0']])
            ->assertRedirect(route('corex.settings.rental-inspections.edit'))->assertSessionHasNoErrors();

        $types = $this->types();
        $this->assertCount(1, $types);
        $this->assertSame('Roof space', $types[0]['label']);
        $this->assertStringStartsWith('custom_', $types[0]['key']);
        $this->assertFalse($types[0]['archived']);

        $keys = RentalInspectionSetting::selectableRoomTypeKeysFor($this->agency->id);
        $this->assertContains($types[0]['key'], $keys);
        $this->assertContains('Kitchen', $keys, 'the 50 standard types are still all offered');
        $this->assertCount(51, $keys);
        $this->assertSame('Roof space', RentalInspectionSetting::roomTypeLabelFor($this->agency->id, $types[0]['key']));
        $this->assertSame('Kitchen', RentalInspectionSetting::roomTypeLabelFor($this->agency->id, 'Kitchen'));
    }

    public function test_a_new_custom_type_starts_on_the_generic_baseline_and_has_a_walking_position(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'DB board']]);
        $key = $this->types()[0]['key'];

        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, RentalInspectionSetting::roomTypeItemsFor($this->agency->id, $key));
        $order = RentalInspectionSetting::roomTypeWalkingOrderFor($this->agency->id);
        $this->assertContains($key, $order);
        $this->assertSame($key, end($order), 'a custom type has no default position, so it is appended last');
        $this->assertCount(51, $order);
    }

    public function test_blank_whitespace_and_overlong_labels_are_absorbed(): void
    {
        $this->saveTypes([
            ['key' => '', 'label' => ''],
            ['key' => '', 'label' => '     '],
            ['key' => '', 'label' => "  Pool    house \t "],
            ['key' => '', 'label' => str_repeat('Ab', 50)],
        ])->assertSessionHasNoErrors();

        $labels = array_column($this->types(), 'label');
        $this->assertCount(2, $labels, 'blank rows are ignored, never saved and never a 500');
        $this->assertSame('Pool house', $labels[0], 'whitespace is trimmed and collapsed');
        $this->assertSame(RentalInspectionSetting::CUSTOM_ROOM_TYPE_LABEL_MAX, mb_strlen($labels[1]), 'an over-long label is cut to the column limit');
        foreach ($this->types() as $t) {
            $this->assertLessThanOrEqual(60, mb_strlen($t['key']), 'the key must fit property_rooms.type');
        }
    }

    public function test_a_label_that_duplicates_a_standard_type_is_skipped_and_reported(): void
    {
        $response = $this->saveTypes([['key' => '', 'label' => 'kitchen'], ['key' => '', 'label' => ' POOL  SHED '], ['key' => '', 'label' => 'Roof space']]);

        $response->assertSessionHas('warning');
        $this->assertStringContainsString('already a standard room type', (string) session('warning'));
        $this->assertSame(['Roof space'], array_column($this->types(), 'label'));
    }

    public function test_a_duplicate_of_its_own_type_is_skipped_even_across_case_and_spacing_and_when_archived(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];

        // same label again, in a second save, new row
        $this->saveTypes([['key' => $key, 'label' => 'Roof space'], ['key' => '', 'label' => ' roof   SPACE ']])
            ->assertSessionHas('warning');
        $this->assertCount(1, $this->types());

        // archive it, then try to add the same name as a NEW row: told to restore instead
        $this->saveTypes([['key' => $key, 'label' => 'Roof space', 'archived' => '1'], ['key' => '', 'label' => 'Roof Space']]);
        $this->assertStringContainsString('archived', (string) session('warning'));
        $this->assertCount(1, $this->types());
        $this->assertTrue($this->types()[0]['archived']);
    }

    public function test_a_posted_key_that_is_not_one_of_ours_is_never_trusted(): void
    {
        $this->saveTypes([['key' => 'Kitchen', 'label' => 'Sun room'], ['key' => 'custom_made_up', 'label' => 'Wine cellar']]);

        $types = $this->types();
        $this->assertCount(2, $types);
        foreach ($types as $t) {
            $this->assertNotSame('Kitchen', $t['key']);
            $this->assertNotSame('custom_made_up', $t['key']);
            $this->assertStringStartsWith('custom_', $t['key']);
        }
    }

    public function test_generated_keys_are_unique_when_two_labels_slug_the_same(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Pool house'], ['key' => '', 'label' => 'Pool-house!'], ['key' => '', 'label' => '???']]);

        $keys = array_column($this->types(), 'key');
        $this->assertCount(3, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)), 'keys never collide, even from labels that slug alike or slug to nothing');
    }

    public function test_the_list_is_capped_not_errored(): void
    {
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['key' => '', 'label' => "Annexe {$i}"];
        }
        $this->saveTypes($rows)->assertSessionHasNoErrors()->assertSessionHas('warning');

        $this->assertCount(RentalInspectionSetting::MAX_CUSTOM_ROOM_TYPES, $this->types());
    }

    public function test_renaming_keeps_the_key_so_existing_rooms_never_orphan(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];
        $room = $this->room($key, 'Roof space 1', ['Trusses']);

        $this->saveTypes([['key' => $key, 'label' => 'Attic']]);

        $this->assertSame($key, $this->types()[0]['key']);
        $this->assertSame('Attic', RentalInspectionSetting::roomTypeLabelFor($this->agency->id, $key));
        $this->assertSame($key, $room->fresh()->type);
    }

    public function test_renaming_to_a_standard_type_name_is_refused_and_the_old_name_kept(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];

        $this->saveTypes([['key' => $key, 'label' => 'Garage']])->assertSessionHas('warning');

        $this->assertSame('Roof space', $this->types()[0]['label']);
    }

    public function test_archiving_hides_a_type_from_new_rooms_but_existing_rooms_keep_it_and_restoring_brings_it_back(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];
        $room = $this->room($key, 'Roof space 1', ['Trusses']);

        $this->saveTypes([['key' => $key, 'label' => 'Roof space', 'archived' => '1']]);

        $this->assertTrue($this->types()[0]['archived']);
        $this->assertNotContains($key, RentalInspectionSetting::selectableRoomTypeKeysFor($this->agency->id));
        $this->assertContains($key, RentalInspectionSetting::knownRoomTypeKeysFor($this->agency->id), 'existing rooms must still resolve');
        $this->assertSame($key, $room->fresh()->type, 'the room is untouched');
        $this->assertSame('Roof space', RentalInspectionSetting::roomTypeLabelFor($this->agency->id, $key));
        $this->assertContains($key, RentalInspectionSetting::roomTypeWalkingOrderFor($this->agency->id), 'an archived type still has a walking position');
        // it is no longer a valid choice for a NEW room
        $this->actingAs($this->agent)->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => 'space', 'label' => 'Roof space 2', 'space_type' => $key,
        ])->assertStatus(422);

        // restore
        $this->saveTypes([['key' => $key, 'label' => 'Roof space', 'archived' => '0']]);
        $this->assertFalse($this->types()[0]['archived']);
        $this->assertContains($key, RentalInspectionSetting::selectableRoomTypeKeysFor($this->agency->id));
    }

    public function test_leaving_a_type_out_of_the_save_archives_it_never_deletes_it(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space'], ['key' => '', 'label' => 'DB board']]);
        $types = $this->types();

        $this->saveTypes([['key' => $types[1]['key'], 'label' => 'DB board']]);

        $after = collect($this->types())->keyBy('key');
        $this->assertCount(2, $after, 'nothing is ever deleted');
        $this->assertTrue($after[$types[0]['key']]['archived']);
        $this->assertFalse($after[$types[1]['key']]['archived']);
    }

    public function test_removing_and_re_adding_the_same_name_in_one_save_keeps_the_same_type(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];

        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);

        $types = $this->types();
        $this->assertCount(1, $types);
        $this->assertSame($key, $types[0]['key'], 'same key, so rooms already filed under it stay linked');
        $this->assertFalse($types[0]['archived']);
    }

    public function test_the_marker_is_required_and_a_save_without_it_changes_nothing(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);

        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.custom-room-types'), [])
            ->assertSessionHasErrors('custom_room_types');
        $this->assertCount(1, $this->types());
        $this->assertFalse($this->types()[0]['archived']);
    }

    /**
     * The permission itself is enforced by the `permission:` route middleware (the test database seeds no role
     * grants, so a denial cannot be driven end to end here) — pin that the saver sits behind the SAME key as every
     * other setting on this screen, and that the top-up routes sit behind `rental_inspections.create` like their siblings.
     */
    public function test_the_new_routes_sit_behind_the_same_permission_keys_as_their_siblings(): void
    {
        $mw = fn (string $name) => \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware();

        $this->assertContains('permission:rental_inspections.manage_settings', $mw('corex.settings.rental-inspections.custom-room-types'));
        $this->assertContains('permission:rental_inspections.create', $mw('corex.properties.rental-inspection-rooms.missing-standard-items'));
        $this->assertContains('permission:rental_inspections.create', $mw('corex.properties.rental-inspection-rooms.add-missing-standard-items'));
    }

    public function test_one_agencys_room_types_are_invisible_to_another(): void
    {
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $key = $this->types()[0]['key'];

        $this->assertSame([], $this->types($other->id));
        $this->assertNotContains($key, RentalInspectionSetting::selectableRoomTypeKeysFor($other->id));
        $this->assertCount(50, RentalInspectionSetting::selectableRoomTypeKeysFor($other->id));

        // No one logged in while the fixture is built: BelongsToAgency stamps the ACTING user's agency on create.
        \Illuminate\Support\Facades\Auth::logout();
        $otherBranch = Branch::forceCreate(['name' => 'CPT', 'agency_id' => $other->id]);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $other->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => '3 Beach Road, Sea Point', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->assertSame($other->id, $otherProperty->agency_id, 'fixture sanity: the property really is the other agency\'s');
        $this->actingAs($otherAgent)->postJson(route('corex.properties.rental-inspection-items.store', $otherProperty), [
            'kind' => 'space', 'label' => 'Roof space 1', 'space_type' => $key,
        ])->assertStatus(422);
    }

    public function test_an_agent_can_add_a_room_of_a_custom_type_and_it_is_seeded_from_the_baseline(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Pool house']]);
        $key = $this->types()[0]['key'];

        $this->actingAs($this->agent)->postJson(route('corex.properties.rental-inspection-items.store', $this->property), [
            'kind' => 'space', 'label' => 'Pool house', 'space_type' => $key,
        ])->assertOk();

        $room = PropertyRoom::where('property_id', $this->property->id)->where('label', 'Pool house')->firstOrFail();
        $this->assertSame($key, $room->type);
        $this->assertEqualsCanonicalizing(
            RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS,
            RentalInspectionItem::where('property_room_id', $room->id)->pluck('label')->all()
        );
    }

    public function test_the_room_type_defaults_and_walking_order_savers_accept_a_custom_key(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Pool house']]);
        $key = $this->types()[0]['key'];

        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.room-type-defaults'), [
            'room_type_item_defaults_submitted' => '1',
            'room_type_item_defaults' => [$key => ['Braai', 'Pool pump'], 'Not A Real Type' => ['x']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Braai', 'Pool pump'], RentalInspectionSetting::roomTypeItemsFor($this->agency->id, $key));
        $this->assertArrayNotHasKey('Not A Real Type', RentalInspectionSetting::customRoomTypeOverridesFor($this->agency->id));

        $order = array_merge([$key], array_values(array_diff(RentalInspectionSetting::roomTypeWalkingOrderFor($this->agency->id), [$key])));
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.room-type-order'), [
            'room_type_walking_order_submitted' => '1', 'room_type_walking_order' => $order,
        ]);
        $this->assertSame($key, RentalInspectionSetting::roomTypeWalkingOrderFor($this->agency->id)[0]);
    }

    public function test_the_settings_page_renders_with_custom_and_archived_types(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space'], ['key' => '', 'label' => 'DB board']]);
        $keys = array_column($this->types(), 'key');
        $this->saveTypes([['key' => $keys[0], 'label' => 'Roof space', 'archived' => '1'], ['key' => $keys[1], 'label' => 'DB board']]);

        $html = $this->actingAs($this->admin)->get(route('corex.settings.rental-inspections.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Your own room types', $html);
        $this->assertStringContainsString('Roof space', $html);
        $this->assertStringContainsString('DB board', $html);
        $this->assertStringContainsString(route('corex.settings.rental-inspections.custom-room-types'), $html);
    }

    // ── Setup Wizard ────────────────────────────────────────────────────

    public function test_the_wizard_saver_is_a_no_op_unless_the_marker_is_posted(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);
        $before = $this->types();

        $request = Request::create('/x', 'POST', ['custom_room_types' => []]);
        $request->setUserResolver(fn () => $this->admin);
        app(RentalListsWizardSaver::class)->inspectionCustomRoomTypes($request);

        $this->assertSame($before, $this->types(), 'a wizard post that never rendered the list must not touch it');
    }

    public function test_the_wizard_saver_saves_and_never_un_archives_what_it_never_rendered(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space'], ['key' => '', 'label' => 'DB board']]);
        [$roof, $db] = $this->types();
        $this->saveTypes([['key' => $roof['key'], 'label' => 'Roof space', 'archived' => '1'], ['key' => $db['key'], 'label' => 'DB board']]);

        // The wizard renders ACTIVE types only (DB board), plus one new one.
        $request = Request::create('/x', 'POST', [
            'custom_room_types_submitted' => '1',
            'custom_room_types' => [['key' => $db['key'], 'label' => 'DB board'], ['key' => '', 'label' => 'Pool house']],
        ]);
        $request->setUserResolver(fn () => $this->admin);
        app(RentalListsWizardSaver::class)->inspectionCustomRoomTypes($request);

        $byLabel = collect($this->types())->keyBy('label');
        $this->assertTrue($byLabel['Roof space']['archived'], 'the archived type the wizard never showed stays archived');
        $this->assertFalse($byLabel['DB board']['archived']);
        $this->assertFalse($byLabel['Pool house']['archived']);
        $this->assertCount(3, $byLabel);
    }

    public function test_the_wizard_rentals_step_renders_the_room_types_control_with_its_explain_text(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Roof space']]);

        $html = view('agency-setup.steps.rentals-inspection-lists', [
            'wzRefusalPresets' => RentalInspectionSetting::refusalReasonPresetsFor($this->agency->id),
            'wzConditionStates' => RentalInspectionSetting::conditionStatesFor($this->agency->id),
            'wzBaselineConditionKey' => RentalInspectionSetting::baselineConditionKeyFor($this->agency->id),
            'wzPhotoClassifications' => RentalInspectionSetting::photoNoteClassificationsFor($this->agency->id),
            'wzInventoryConditionStates' => \App\Models\RentalInventorySetting::conditionStatesFor($this->agency->id),
            'wzCustomRoomTypes' => $this->types(),
        ])->render();

        $this->assertStringContainsString('custom_room_types_submitted', $html);
        $this->assertStringContainsString('Your own room types', $html);
        $this->assertStringContainsString('What this changes:', $html);
        $this->assertStringContainsString('Roof space', $html);
    }

    public function test_the_wizard_config_registers_the_room_types_saver_on_the_rentals_step(): void
    {
        $savers = collect(config('agency-onboarding-copy.leases.savers', []));

        $this->assertTrue($savers->contains(fn ($s) => ($s['controller'] ?? null) === RentalListsWizardSaver::class && ($s['method'] ?? null) === 'inspectionCustomRoomTypes'));
    }

    // ── "Add missing standard items" ────────────────────────────────────

    private function missingUrl(PropertyRoom $room, ?Property $property = null): string
    {
        return route('corex.properties.rental-inspection-rooms.missing-standard-items', [$property ?? $this->property, $room->id]);
    }

    private function topUpUrl(PropertyRoom $room, ?Property $property = null): string
    {
        return route('corex.properties.rental-inspection-rooms.add-missing-standard-items', [$property ?? $this->property, $room->id]);
    }

    public function test_the_preview_lists_only_what_the_thin_room_lacks(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling', 'Walls', 'Windows']);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();

        $standard = RentalInspectionSetting::DEFAULT_ROOM_FAMILY_ITEMS['bedroom'];
        $this->assertSame(array_values(array_diff($standard, ['Ceiling', 'Walls', 'Windows'])), $json['missing']);
        $this->assertSame(['Ceiling', 'Walls', 'Windows'], $json['present']);
        $this->assertSame('Bedroom 1', $json['room_label']);
    }

    public function test_matching_ignores_case_and_spacing(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['  ceiling ', 'WALLS', 'Floor   Covering']);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();

        foreach (['Ceiling', 'Walls', 'Floor covering'] as $label) {
            $this->assertNotContains($label, $json['missing']);
            $this->assertContains($label, $json['present']);
        }
    }

    public function test_a_room_that_already_has_everything_has_nothing_to_add(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', RentalInspectionSetting::DEFAULT_ROOM_FAMILY_ITEMS['bedroom']);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();
        $this->assertSame([], $json['missing']);

        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Skirting']])
            ->assertOk()->assertJson(['added' => 0]);
        $this->assertSame(count(RentalInspectionSetting::DEFAULT_ROOM_FAMILY_ITEMS['bedroom']), RentalInspectionItem::where('property_room_id', $room->id)->count());
    }

    public function test_a_retired_item_with_the_same_label_counts_as_present_and_is_never_re_added(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);
        RentalInspectionItem::where('property_room_id', $room->id)->update(['is_retired' => true]);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();
        $this->assertNotContains('Ceiling', $json['missing'], 'an agent who retired an item made a decision; a top-up must not undo it');
        $this->assertContains('Ceiling', $json['present']);

        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Ceiling']])->assertOk()->assertJson(['added' => 0]);
        $this->assertSame(1, RentalInspectionItem::where('property_room_id', $room->id)->count());
    }

    public function test_only_the_ticked_items_are_added_appended_after_the_existing_ones_with_nothing_else_touched(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling', 'Walls']);
        $existing = RentalInspectionItem::where('property_room_id', $room->id)->orderBy('id')->get();
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
        $inspection = \App\Models\RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => \App\Models\RentalInspection::TYPE_IN,
            'created_by_user_id' => $this->agent->id,
        ]);
        $obs = RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $existing[0]->id,
            'condition' => 'good', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION, 'observed_by_user_id' => $this->agent->id,
            'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now(),
        ]);
        $obsCountBefore = RentalInspectionObservation::count();

        $response = $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Skirting', 'Light switches']])
            ->assertOk()->assertJson(['added' => 2]);

        $labels = collect($response->json('items'))->pluck('label')->all();
        $this->assertSame(['Skirting', 'Light switches'], $labels);

        $all = RentalInspectionItem::where('property_room_id', $room->id)->orderBy('sort_order')->orderBy('id')->get();
        $this->assertSame(['Ceiling', 'Walls', 'Skirting', 'Light switches'], $all->pluck('label')->all(), 'new items go after the existing ones');
        foreach ($existing as $before) {
            $now = $all->firstWhere('id', $before->id);
            $this->assertSame($before->sort_order, $now->sort_order, 'existing items are never reordered');
            $this->assertFalse((bool) $now->is_retired);
        }
        foreach ($all->whereNotIn('id', $existing->pluck('id')) as $new) {
            $this->assertSame($room->id, $new->property_room_id);
            $this->assertSame('Bedroom', $new->space_type);
            $this->assertSame($this->agency->id, $new->agency_id);
            $this->assertSame($this->agent->id, $new->created_by_user_id);
        }
        $this->assertSame($obsCountBefore, RentalInspectionObservation::count(), 'no observation is read or written');
        $this->assertSame('good', $obs->fresh()->condition);
        $this->assertNotContains('Plug sockets', $all->pluck('label')->all(), 'unticked items are not added');
    }

    public function test_a_stale_or_forged_label_adds_nothing(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);

        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Hacked item', '<script>alert(1)</script>', 'Ceiling']])
            ->assertOk()->assertJson(['added' => 0]);
        $this->assertSame(['Ceiling'], RentalInspectionItem::where('property_room_id', $room->id)->pluck('label')->all());
    }

    public function test_repeating_the_same_confirmed_top_up_adds_nothing_the_second_time(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);

        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Skirting']])->assertOk()->assertJson(['added' => 1]);
        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Skirting']])->assertOk()->assertJson(['added' => 0]);

        $this->assertSame(1, RentalInspectionItem::where('property_room_id', $room->id)->where('label', 'Skirting')->count());
    }

    public function test_nothing_ticked_is_refused_with_a_plain_message(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);

        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => []])->assertStatus(422)
            ->assertJsonValidationErrors('labels');
        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), [])->assertStatus(422);
    }

    public function test_the_agencys_own_checklist_for_the_type_is_what_the_room_is_measured_against(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'room_type_item_defaults' => ['Bedroom' => ['Aircon', 'Mirror']]]);
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling', 'mirror']);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();

        $this->assertSame(['Aircon'], $json['missing']);
        $this->assertSame(['Mirror'], $json['present']);
    }

    public function test_a_room_of_a_custom_type_is_measured_against_the_generic_baseline(): void
    {
        $this->saveTypes([['key' => '', 'label' => 'Pool house']]);
        $key = $this->types()[0]['key'];
        $room = $this->room($key, 'Pool house', ['Ceiling']);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();

        $this->assertSame(array_values(array_diff(RentalInspectionSetting::DEFAULT_ROOM_TYPE_ITEMS, ['Ceiling'])), $json['missing']);
    }

    public function test_a_room_with_no_items_at_all_gets_the_whole_checklist_offered(): void
    {
        $room = $this->room('Kitchen', 'Kitchen', []);

        $json = $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertOk()->json();

        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_FAMILY_ITEMS['kitchen'], $json['missing']);
        $this->assertSame([], $json['present']);
    }

    public function test_a_retired_room_a_room_of_another_property_and_a_made_up_room_are_refused(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);
        $room->update(['is_retired' => true]);
        $this->actingAs($this->agent)->getJson($this->missingUrl($room))->assertStatus(422);
        $this->actingAs($this->agent)->postJson($this->topUpUrl($room), ['labels' => ['Skirting']])->assertStatus(422);

        $branch = Branch::forceCreate(['name' => 'Second', 'agency_id' => $this->agency->id]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => 'Another property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $liveRoom = $this->room('Bedroom', 'Bedroom 2', ['Ceiling']);
        // the room belongs to $this->property, so addressing it through ANOTHER property is a 404
        $this->actingAs($this->agent)->getJson($this->missingUrl($liveRoom, $otherProperty))->assertNotFound();
        $this->actingAs($this->agent)->postJson($this->topUpUrl($liveRoom, $otherProperty), ['labels' => ['Skirting']])->assertNotFound();
        $this->actingAs($this->agent)->getJson(route('corex.properties.rental-inspection-rooms.missing-standard-items', [$this->property, 999999]))->assertNotFound();
    }

    public function test_another_agencys_property_and_room_cannot_be_reached_by_direct_url(): void
    {
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt2-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'CPT', 'agency_id' => $other->id]);
        $otherAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
        $otherProperty = Property::forceCreate([
            'agency_id' => $other->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => '3 Beach Road, Sea Point', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $theirRoom = $this->room('Bedroom', 'Their bedroom', ['Ceiling'], $otherProperty);

        // our agent, THEIR property + room
        $this->actingAs($this->agent)->getJson($this->missingUrl($theirRoom, $otherProperty))->assertStatus(404);
        $this->actingAs($this->agent)->postJson($this->topUpUrl($theirRoom, $otherProperty), ['labels' => ['Skirting']])->assertStatus(404);
        // our agent, OUR property, THEIR room id
        $this->actingAs($this->agent)->getJson($this->missingUrl($theirRoom))->assertStatus(404);
        $this->assertSame(['Ceiling'], RentalInspectionItem::withoutGlobalScopes()->where('property_room_id', $theirRoom->id)->pluck('label')->all());
    }

    public function test_a_user_without_the_inspections_create_permission_is_refused(): void
    {
        $room = $this->room('Bedroom', 'Bedroom 1', ['Ceiling']);
        $nobody = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'role' => 'viewer', 'is_active' => true]);

        $status = $this->actingAs($nobody)->postJson($this->topUpUrl($room), ['labels' => ['Skirting']])->status();
        $this->assertContains($status, [403, 404], 'a user without rental_inspections.create never reaches the action');
        $this->assertSame(1, RentalInspectionItem::where('property_room_id', $room->id)->count());
    }
}
