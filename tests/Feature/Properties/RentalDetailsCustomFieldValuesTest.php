<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyRentalDetailsCustomField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-property-tab.md §2/§8, Part 2 — the agency's defined
 * fields (Part 1) actually rendering and saving on the property Rental
 * tab. Values save against the PROPERTY, merged (never wholesale-replaced)
 * so a retired/hidden field's already-captured value survives.
 */
final class RentalDetailsCustomFieldValuesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function rentalProperty(): Property
    {
        return Property::create([
            'title' => 'Test Rental', 'agency_id' => $this->agency->id,
            'agent_id' => $this->owner->id, 'branch_id' => $this->branch->id,
            'listing_type' => 'rental', 'listing_type_pending' => false,
        ]);
    }

    private function field(string $label, string $type, bool $required = false): PropertyRentalDetailsCustomField
    {
        return PropertyRentalDetailsCustomField::create([
            'agency_id' => $this->agency->id,
            'key' => PropertyRentalDetailsCustomField::generateKey($this->agency->id, $label),
            'label' => $label, 'field_type' => $type, 'required' => $required, 'sort_order' => 0,
        ]);
    }

    // ── rendering ────────────────────────────────────────────────────────

    public function test_an_agency_with_no_fields_renders_nothing_and_the_page_still_loads(): void
    {
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertDontSee('custom_fields', false);
    }

    public function test_a_defined_field_renders_on_the_rental_tab(): void
    {
        $this->field('Lets Assist', PropertyRentalDetailsCustomField::TYPE_YES_NO);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertStatus(200);
        $resp->assertSee('Lets Assist', false);
    }

    public function test_a_hidden_field_does_not_render(): void
    {
        $field = $this->field('Hidden Field', PropertyRentalDetailsCustomField::TYPE_TEXT);
        $field->update(['shown' => false]);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertDontSee('Hidden Field', false);
    }

    public function test_another_agencys_field_never_appears_on_this_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        PropertyRentalDetailsCustomField::create([
            'agency_id' => $otherAgency->id, 'key' => 'custom_theirs', 'label' => 'Their Field',
            'field_type' => 'text', 'sort_order' => 0,
        ]);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->get(route('corex.properties.show', $property));

        $resp->assertDontSee('Their Field', false);
    }

    // ── saving ───────────────────────────────────────────────────────────

    public function test_saving_a_yes_no_field_value_persists_against_the_property(): void
    {
        $field = $this->field('Lets Assist', PropertyRentalDetailsCustomField::TYPE_YES_NO);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$field->key => '1'],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(true, $property->fresh()->rental_details_custom_field_values[$field->key]);
    }

    public function test_saving_a_currency_field_value_persists_as_a_number(): void
    {
        $field = $this->field('Key Deposit', PropertyRentalDetailsCustomField::TYPE_CURRENCY);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$field->key => '1500.50'],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(1500.50, (float) $property->fresh()->rental_details_custom_field_values[$field->key]);
    }

    public function test_a_negative_currency_value_is_rejected(): void
    {
        $field = $this->field('Key Deposit', PropertyRentalDetailsCustomField::TYPE_CURRENCY);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$field->key => '-50'],
        ])->assertSessionHasErrors("custom_fields.{$field->key}");

        $this->assertNull($property->fresh()->rental_details_custom_field_values);
    }

    // ── required enforcement — no silent "Saved." on a real failure ───────

    public function test_a_missing_required_field_fails_the_save_visibly_and_persists_nothing(): void
    {
        $field = $this->field('Pet Deposit', PropertyRentalDetailsCustomField::TYPE_CURRENCY, required: true);
        $property = $this->rentalProperty();

        $resp = $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'rental_amount' => '9000', // a shipped field, present and valid
            // custom_fields omitted entirely -- the required custom field is missing
        ]);

        $resp->assertSessionHasErrors("custom_fields.{$field->key}");
        // The whole save must fail together -- the shipped field change must
        // NOT have been persisted either, exactly the atomicity the
        // onboarding wizard bug (2026-09-20) was fixed for.
        $this->assertNull($property->fresh()->rental_amount, 'a failing required custom field must roll back the whole save, not partially apply it');
    }

    public function test_a_required_yes_no_field_is_satisfied_by_an_explicit_no(): void
    {
        // The hidden-input-plus-checkbox pattern: an unticked box still
        // submits "0", which must satisfy "required" (an explicit answer),
        // not be treated as absent.
        $field = $this->field('Lets Assist', PropertyRentalDetailsCustomField::TYPE_YES_NO, required: true);
        $property = $this->rentalProperty();

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$field->key => '0'],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(false, $property->fresh()->rental_details_custom_field_values[$field->key]);
    }

    // ── the no-hard-delete / merge guarantee ────────────────────────────

    public function test_retiring_a_field_never_touches_the_propertys_already_saved_value(): void
    {
        $field = $this->field('Lets Assist', PropertyRentalDetailsCustomField::TYPE_YES_NO);
        $property = $this->rentalProperty();
        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$field->key => '1'],
        ]);
        $field->delete(); // retired

        $this->assertSame(true, $property->fresh()->rental_details_custom_field_values[$field->key]);
    }

    public function test_saving_one_active_field_never_wipes_a_hidden_fields_already_saved_value(): void
    {
        $shown = $this->field('Shown Field', PropertyRentalDetailsCustomField::TYPE_TEXT);
        $hidden = $this->field('Hidden Field', PropertyRentalDetailsCustomField::TYPE_TEXT);
        $property = $this->rentalProperty();

        // Both have values while both are shown.
        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$shown->key => 'A', $hidden->key => 'B'],
        ]);

        // Now hide one -- it no longer renders, so a real form submission
        // from this point on never includes its key at all.
        $hidden->update(['shown' => false]);

        $this->actingAs($this->owner)->put(route('corex.properties.rental-details.update', $property), [
            'custom_fields' => [$shown->key => 'A2'],
        ])->assertSessionDoesntHaveErrors();

        $values = $property->fresh()->rental_details_custom_field_values;
        $this->assertSame('A2', $values[$shown->key]);
        $this->assertSame('B', $values[$hidden->key], 'a wholesale overwrite would have wiped this');
    }
}
