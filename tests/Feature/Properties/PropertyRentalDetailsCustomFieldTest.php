<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PropertyRentalDetailsCustomField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-property-tab.md §2/§8, Part 1 — the definition side
 * only. Full CRUD (create/update/archive/restore/reorder), same shape and
 * same test coverage class as RentalApplicationCustomFieldTest — the
 * pattern this feature deliberately mirrors.
 */
final class PropertyRentalDetailsCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // ── generateKey() ───────────────────────────────────────────────────

    public function test_generate_key_slugifies_and_namespaces_the_label(): void
    {
        $key = PropertyRentalDetailsCustomField::generateKey($this->agency->id, 'Lets Assist');

        $this->assertSame('custom_lets_assist', $key);
    }

    public function test_generate_key_dedupes_against_every_key_this_agency_has_ever_used_including_retired(): void
    {
        PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'sort_order' => 0,
        ])->delete(); // retired

        $key = PropertyRentalDetailsCustomField::generateKey($this->agency->id, 'Lets Assist');

        $this->assertSame('custom_lets_assist_2', $key);
    }

    // ── store() ──────────────────────────────────────────────────────────

    public function test_store_creates_a_field_with_a_generated_key(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.store'), [
            'label' => 'Lets Assist', 'field_type' => 'yes_no', 'help_text' => 'HFC-only utility management service',
        ])->assertSessionDoesntHaveErrors();

        $field = PropertyRentalDetailsCustomField::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('custom_lets_assist', $field->key);
        $this->assertSame('Lets Assist', $field->label);
        $this->assertTrue($field->shown, 'shown defaults true on creation');
        $this->assertFalse($field->required, 'required defaults false when unchecked');
        $this->assertFalse($field->advertise, 'advertise defaults false when unchecked');
        $this->assertSame($owner->id, $field->created_by);
    }

    public function test_store_a_currency_field_with_advertise_ticked(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.store'), [
            'label' => 'Key Deposit', 'field_type' => 'currency', 'advertise' => '1',
        ])->assertSessionDoesntHaveErrors();

        $field = PropertyRentalDetailsCustomField::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame(PropertyRentalDetailsCustomField::TYPE_CURRENCY, $field->field_type);
        $this->assertTrue($field->advertise);
    }

    public function test_store_rejects_a_type_not_in_the_four_offered(): void
    {
        $owner = $this->owner();

        // choice_list/date/file exist on the underlying mechanism but are
        // deliberately not offered here (spec §2.1/§9) -- confirm the
        // validator actually rejects them, not just that the UI omits them.
        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.store'), [
            'label' => 'Bad', 'field_type' => 'choice_list',
        ])->assertSessionHasErrors('field_type');

        $this->assertSame(0, PropertyRentalDetailsCustomField::where('agency_id', $this->agency->id)->count());
    }

    public function test_store_rejects_a_duplicate_label(): void
    {
        $owner = $this->owner();
        PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.store'), [
            'label' => 'Lets Assist', 'field_type' => 'yes_no',
        ])->assertSessionHasErrors('label');

        $this->assertSame(1, PropertyRentalDetailsCustomField::where('agency_id', $this->agency->id)->count());
    }

    public function test_store_allows_a_label_matching_a_retired_field(): void
    {
        $owner = $this->owner();
        PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'sort_order' => 0,
        ])->delete();

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.store'), [
            'label' => 'Lets Assist', 'field_type' => 'yes_no',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2, PropertyRentalDetailsCustomField::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    // ── update() ─────────────────────────────────────────────────────────

    public function test_update_can_hide_a_field_without_retiring_it(): void
    {
        $owner = $this->owner();
        $field = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'shown' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-details.custom-fields.update', $field), [
            'label' => 'Lets Assist', 'field_type' => 'yes_no',
            // 'shown' omitted — an unchecked checkbox.
        ])->assertSessionDoesntHaveErrors();

        $field->refresh();
        $this->assertFalse($field->shown);
        $this->assertNull($field->deleted_at, 'hiding is not the same as retiring');
    }

    public function test_update_can_toggle_advertise_independently_of_shown(): void
    {
        $owner = $this->owner();
        $field = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'shown' => true, 'advertise' => false, 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-details.custom-fields.update', $field), [
            'label' => 'Lets Assist', 'field_type' => 'yes_no', 'shown' => '1', 'advertise' => '1',
        ])->assertSessionDoesntHaveErrors();

        $field->refresh();
        $this->assertTrue($field->shown);
        $this->assertTrue($field->advertise);
    }

    public function test_a_user_cannot_update_another_agencys_field(): void
    {
        $owner = $this->owner();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $foreign = PropertyRentalDetailsCustomField::create([
            'agency_id' => $otherAgency->id, 'key' => 'custom_not_yours', 'label' => 'Not Yours',
            'field_type' => 'text', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-details.custom-fields.update', $foreign), [
            'label' => 'Hijacked', 'field_type' => 'text',
        ])->assertNotFound();

        $this->assertSame('Not Yours', $foreign->fresh()->label);
    }

    // ── archive() / restore() ──────────────────────────────────────────────
    //
    // NOTE: the historical-integrity assertion (archiving a definition never
    // touches an already-captured VALUE) is a Part 2 test, not Part 1 —
    // properties.rental_details_custom_field_values doesn't exist until
    // Part 2 wires the field onto the property Rental tab. Part 1 only
    // proves the definition's own lifecycle.

    public function test_archiving_a_field_soft_deletes_it_only(): void
    {
        $owner = $this->owner();
        $field = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.archive', $field))
            ->assertSessionDoesntHaveErrors();

        $this->assertNotNull($field->fresh()->deleted_at);
        $this->assertDatabaseHas('property_rental_details_custom_fields', ['id' => $field->id]);
    }

    public function test_restore_brings_a_retired_field_back(): void
    {
        $owner = $this->owner();
        $field = PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_lets_assist', 'label' => 'Lets Assist',
            'field_type' => 'yes_no', 'sort_order' => 0,
        ]);
        $field->delete();

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.restore', $field->id))
            ->assertSessionDoesntHaveErrors();

        $this->assertNull($field->fresh()->deleted_at);
    }

    // ── reorder() ────────────────────────────────────────────────────────

    public function test_reorder_persists_the_new_sort_order(): void
    {
        $owner = $this->owner();
        $a = PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_a', 'label' => 'A', 'field_type' => 'text', 'sort_order' => 0]);
        $b = PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_b', 'label' => 'B', 'field_type' => 'text', 'sort_order' => 1]);

        $this->actingAs($owner)->post(route('corex.settings.rental-details.custom-fields.reorder'), [
            'order' => [$b->id, $a->id],
        ])->assertSessionDoesntHaveErrors();

        $reloaded = PropertyRentalDetailsCustomField::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $reloaded);
    }

    // ── allFor() / activeFor() ──────────────────────────────────────────

    public function test_active_for_excludes_retired_and_hidden_fields(): void
    {
        PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_shown', 'label' => 'Shown', 'field_type' => 'text', 'shown' => true, 'sort_order' => 0]);
        PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_hidden', 'label' => 'Hidden', 'field_type' => 'text', 'shown' => false, 'sort_order' => 1]);
        PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_retired', 'label' => 'Retired', 'field_type' => 'text', 'shown' => true, 'sort_order' => 2])->delete();

        $active = PropertyRentalDetailsCustomField::activeFor($this->agency->id);

        $this->assertSame(['Shown'], $active->pluck('label')->all());
    }

    public function test_all_for_includes_retired_fields(): void
    {
        PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_retired', 'label' => 'Retired', 'field_type' => 'text', 'sort_order' => 0])->delete();

        $all = PropertyRentalDetailsCustomField::allFor($this->agency->id);

        $this->assertSame(['Retired'], $all->pluck('label')->all());
    }

    // ── cross-agency isolation ────────────────────────────────────────────

    public function test_one_agencys_fields_never_leak_into_another(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        PropertyRentalDetailsCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_mine', 'label' => 'Mine', 'field_type' => 'text', 'sort_order' => 0]);
        PropertyRentalDetailsCustomField::create(['agency_id' => $otherAgency->id, 'key' => 'custom_theirs', 'label' => 'Theirs', 'field_type' => 'text', 'sort_order' => 0]);

        $this->assertSame(['Mine'], PropertyRentalDetailsCustomField::activeFor($this->agency->id)->pluck('label')->all());
        $this->assertSame(['Theirs'], PropertyRentalDetailsCustomField::activeFor($otherAgency->id)->pluck('label')->all());
    }
}
