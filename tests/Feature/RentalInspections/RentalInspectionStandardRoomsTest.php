<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Http\Controllers\CoreX\RentalListsWizardSaver;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §55 - "Build rooms from the standard checklist": the agency's room list per kind of
 * property (house / flat / townhouse / other), the one-press build for a property with no rooms, and the settings +
 * Setup Wizard savers. Second agency proven throughout (CLAUDE.md rule 9).
 */
final class RentalInspectionStandardRoomsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'SR Agency', 'slug' => 'sr-agency-' . uniqid()]);
        $this->other = Agency::create(['name' => 'SR Other', 'slug' => 'sr-other-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    private function property(array $extra = [], ?Agency $agency = null): Property
    {
        $agency ??= $this->agency;
        $branchId = $agency->id === $this->agency->id ? $this->agent->branch_id : Branch::forceCreate(['name' => 'B2', 'agency_id' => $agency->id])->id;

        return Property::forceCreate(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branchId,
            'title' => '9 Beach Rd', 'status' => 'active', 'listing_type' => 'rental', 'property_type' => 'House',
        ], $extra));
    }

    private function build(Property $p, ?User $as = null, bool $json = true)
    {
        $req = $this->actingAs($as ?? $this->agent);
        $url = route('corex.properties.rental-inspection-items.seed-from-standard', $p);

        return $json ? $req->postJson($url) : $req->post($url);
    }

    // ── Which list a property uses ──────────────────────────────────────

    public function test_property_type_names_map_to_a_list(): void
    {
        $f = fn (?string $t) => RentalInspectionSetting::roomTemplateFamilyFor($t);
        $this->assertSame('house', $f('House'));
        $this->assertSame('flat', $f('Apartment / Flat'));
        $this->assertSame('flat', $f('Studio'));
        $this->assertSame('townhouse', $f('Townhouse'));
        $this->assertSame('townhouse', $f('Cluster'));
        $this->assertSame('other', $f('Commercial Property'));
        $this->assertSame('other', $f('Warehouse'), 'a word that merely contains "house" is not a house');
        $this->assertSame('house', $f('Guest House'));
        $this->assertSame('other', $f(null));
        $this->assertSame('other', $f(''));
    }

    public function test_every_agency_starts_with_sensible_lists_without_saving_anything(): void
    {
        foreach ([$this->agency->id, $this->other->id] as $id) {
            $lists = RentalInspectionSetting::roomTemplatesFor($id);
            $this->assertSame(['house', 'flat', 'townhouse', 'other'], array_keys($lists));
            foreach ($lists as $rows) {
                $this->assertNotEmpty($rows);
            }
        }
        $this->assertSame(0, RentalInspectionSetting::withoutGlobalScopes()->count(), 'read-time defaults - nothing is stored');
    }

    // ── The build ────────────────────────────────────────────────────────

    public function test_a_property_with_no_rooms_gets_the_house_list_with_items_in_walking_order(): void
    {
        $p = $this->property();

        $this->build($p)->assertOk()->assertJsonPath('built', 11);

        $rooms = PropertyRoom::where('property_id', $p->id)->orderBy('sort_order')->get();
        $labels = $rooms->pluck('label')->all();
        $this->assertCount(11, $rooms);
        $this->assertSame('Entrance Hall', $labels[0], 'rooms follow the agency walking order');
        $this->assertLessThan(array_search('Bathroom 1', $labels, true), array_search('Bedroom 3', $labels, true));
        $this->assertContains('Bedroom 1', $labels);
        $this->assertContains('Kitchen', $labels, 'a room asked for once is not numbered');
        $this->assertSame('standard_checklist', $rooms->first()->source);

        $kitchen = $rooms->firstWhere('label', 'Kitchen');
        $expected = RentalInspectionSetting::roomTypeItemsFor($this->agency->id, 'Kitchen');
        $this->assertSame($expected, RentalInspectionItem::where('property_room_id', $kitchen->id)->orderBy('id')->pluck('label')->all());
        $this->assertNotNull($p->fresh()->rental_inspection_form_seeded_at);
        $this->assertSame($this->agency->id, $kitchen->agency_id);
    }

    public function test_bedrooms_bathrooms_and_garages_follow_the_property_figures(): void
    {
        $p = $this->property(['beds' => 2, 'baths' => 1, 'garages' => 2]);

        $this->build($p)->assertOk();

        $labels = PropertyRoom::where('property_id', $p->id)->pluck('label')->all();
        $this->assertContains('Bedroom 2', $labels);
        $this->assertNotContains('Bedroom 3', $labels);
        $this->assertContains('Bathroom', $labels, 'one bathroom is just "Bathroom"');
        $this->assertContains('Garage 2', $labels);
    }

    public function test_a_flat_uses_the_flat_list(): void
    {
        $p = $this->property(['property_type' => 'Apartment / Flat']);

        $this->build($p)->assertOk();

        $types = PropertyRoom::where('property_id', $p->id)->pluck('type')->unique()->values()->all();
        $this->assertContains('Patio', $types);
        $this->assertNotContains('Garden', $types);
    }

    public function test_a_property_that_already_has_rooms_is_untouched(): void
    {
        $p = $this->property();
        PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $p->id, 'type' => 'Kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->agent->id]);

        $this->build($p)->assertStatus(409);

        $this->assertSame(1, PropertyRoom::where('property_id', $p->id)->count());
        $this->assertNull($p->fresh()->rental_inspection_form_seeded_at);
    }

    public function test_pressing_it_twice_builds_once(): void
    {
        $p = $this->property();

        $this->build($p)->assertOk();
        $count = PropertyRoom::where('property_id', $p->id)->count();
        $this->build($p)->assertStatus(409);

        $this->assertSame($count, PropertyRoom::where('property_id', $p->id)->count());
    }

    public function test_a_retired_room_still_counts_as_having_rooms(): void
    {
        $p = $this->property();
        PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $p->id, 'type' => 'Kitchen', 'label' => 'Kitchen', 'source' => 'manual', 'sort_order' => 0, 'is_retired' => true, 'created_by_user_id' => $this->agent->id]);

        $this->build($p)->assertStatus(409);
    }

    public function test_an_empty_list_for_the_kind_of_property_says_so(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.room-templates'), [
            'room_templates_submitted' => '1',
            'room_templates' => ['house' => ['_present' => ['type' => '']]],
        ])->assertSessionHasNoErrors();
        $p = $this->property();

        $this->build($p)->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'empty'));
        $this->assertSame(0, PropertyRoom::where('property_id', $p->id)->count());
    }

    public function test_the_plain_form_post_from_the_inspection_screen_redirects_back_with_a_message(): void
    {
        $p = $this->property();

        $this->from('/somewhere')->actingAs($this->agent)
            ->post(route('corex.properties.rental-inspection-items.seed-from-standard', $p))
            ->assertRedirect('/somewhere')->assertSessionHas('success');

        $this->assertGreaterThan(0, PropertyRoom::where('property_id', $p->id)->count());
    }

    public function test_another_agencys_property_cannot_be_built(): void
    {
        $theirs = $this->property([], $this->other);

        $res = $this->build($theirs);

        $this->assertContains($res->getStatusCode(), [403, 404]);
        $this->assertSame(0, PropertyRoom::where('property_id', $theirs->id)->count());
    }

    // ── Settings + wizard ────────────────────────────────────────────────

    public function test_an_agency_can_change_its_house_list_and_a_second_agency_keeps_the_defaults(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.room-templates'), [
            'room_templates_submitted' => '1',
            'room_templates' => ['house' => [
                ['type' => 'Lounge', 'count' => 1],
                ['type' => 'Bedroom', 'count' => 2],
                ['type' => 'Bedroom', 'count' => 5],       // duplicate type in one list is dropped
                ['type' => 'Not A Room', 'count' => 1],    // unknown type is dropped
                ['type' => 'Bathroom', 'count' => 999],    // clamped
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            [['type' => 'Lounge', 'count' => 1], ['type' => 'Bedroom', 'count' => 2], ['type' => 'Bathroom', 'count' => RentalInspectionSetting::ROOM_TEMPLATE_MAX_COUNT]],
            RentalInspectionSetting::roomTemplateFor($this->agency->id, 'house'),
        );
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TEMPLATES['house'], RentalInspectionSetting::roomTemplateFor($this->other->id, 'house'));
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TEMPLATES['flat'], RentalInspectionSetting::roomTemplateFor($this->agency->id, 'flat'), 'a kind that was not posted is untouched');

        $p = $this->property();
        $this->build($p)->assertOk()->assertJsonPath('built', 23);
    }

    public function test_the_agencys_own_room_type_can_be_in_a_list(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.custom-room-types'), [
            'custom_room_types_submitted' => '1', 'custom_room_types' => [['key' => '', 'label' => 'Roof space']],
        ]);
        $key = RentalInspectionSetting::customRoomTypesFor($this->agency->id)[0]['key'];

        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.room-templates'), [
            'room_templates_submitted' => '1', 'room_templates' => ['other' => [['type' => $key, 'count' => 1]]],
        ]);
        $p = $this->property(['property_type' => 'Warehouse']);
        $this->build($p)->assertOk()->assertJsonPath('built', 1);

        $this->assertSame($key, PropertyRoom::where('property_id', $p->id)->first()->type);
    }

    public function test_the_settings_page_shows_the_lists(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings.rental-inspections.edit'))
            ->assertOk()->assertSee('Standard rooms for a new property')->assertSee('room-template-house', false)->assertSee('room-template-other', false);
    }

    public function test_the_wizard_saver_is_a_no_op_without_the_marker_and_saves_with_it(): void
    {
        $this->actingAs($this->admin);
        $saver = app(RentalListsWizardSaver::class);

        $saver->inspectionRoomTemplates(Request::create('/x', 'POST', ['room_templates' => ['house' => []]]));
        $this->assertSame([], RentalInspectionSetting::roomTemplateOverridesFor($this->agency->id));

        $req = Request::create('/x', 'POST', ['room_templates_submitted' => '1', 'room_templates' => ['flat' => [['type' => 'Lounge', 'count' => 1]]]]);
        $req->setUserResolver(fn () => $this->admin);
        $saver->inspectionRoomTemplates($req);
        $this->assertSame([['type' => 'Lounge', 'count' => 1]], RentalInspectionSetting::roomTemplateFor($this->agency->id, 'flat'));
        $this->assertSame(RentalInspectionSetting::DEFAULT_ROOM_TEMPLATES['house'], RentalInspectionSetting::roomTemplateFor($this->agency->id, 'house'));
    }

    public function test_the_wizard_step_lists_the_saver(): void
    {
        $this->assertStringContainsString("'method' => 'inspectionRoomTemplates'", file_get_contents(base_path('config/agency-onboarding-copy.php')));
        $this->assertStringContainsString('room_templates_submitted', file_get_contents(resource_path('views/agency-setup/steps/rentals-inspection-lists.blade.php')));
    }
}
